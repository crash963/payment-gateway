<?php

namespace App\Services\Copilot;

use App\Models\Merchant;
use App\Services\Copilot\Tools\CopilotTool;

/**
 * Orchestrates one merchant chat turn: sends the conversation + tool schemas to
 * OpenAI, executes any requested tool calls (scoped to $merchant - see CopilotTool),
 * feeds the results back, and repeats until the model returns a plain text answer (no
 * more tool calls) or a bounded number of rounds is exhausted.
 *
 * Stateless by design: the caller (CopilotController) sends the full prior
 * conversation on every request and gets the full updated conversation back to resend
 * next turn - no server-side session/conversation model. See storage/docs for why
 * this was chosen over persisting conversations.
 */
class CopilotService
{
    /** @var array<string, CopilotTool> */
    private array $tools;

    private const MAX_TOOL_ROUNDS = 5;

    /**
     * @param  CopilotTool[]  $tools
     */
    public function __construct(private readonly OpenAiClient $client, array $tools)
    {
        $this->tools = collect($tools)->keyBy(fn (CopilotTool $tool) => $tool->name())->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages  prior conversation (user/assistant/tool turns) - no system message, that's added here
     * @return array{message: ?string, conversation: array<int, array<string, mixed>>}
     */
    public function chat(Merchant $merchant, array $messages): array
    {
        $conversation = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ...$messages,
        ];

        $toolSchemas = $this->toolSchemas();

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->client->chat($conversation, $toolSchemas);
            $message = $response['choices'][0]['message'];
            $conversation[] = $message;

            if (empty($message['tool_calls'])) {
                return ['message' => $message['content'] ?? null, 'conversation' => $conversation];
            }

            foreach ($message['tool_calls'] as $toolCall) {
                $conversation[] = $this->executeToolCall($merchant, $toolCall);
            }
        }

        // A model that keeps calling tools forever shouldn't hang the request - bail
        // out with a clear message rather than looping until a request timeout does
        // it for us with no useful error.
        return [
            'message' => "I wasn't able to finish answering that within the allowed number of steps - could you try rephrasing or narrowing the question?",
            'conversation' => $conversation,
        ];
    }

    /**
     * @param  array<string, mixed>  $toolCall
     * @return array<string, mixed> a "tool" role message for the conversation
     */
    private function executeToolCall(Merchant $merchant, array $toolCall): array
    {
        $name = $toolCall['function']['name'] ?? '';
        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];

        $tool = $this->tools[$name] ?? null;

        $result = $tool
            ? $tool->execute($merchant, $arguments)
            : ['error' => "Unknown tool: {$name}"];

        return [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'],
            'content' => json_encode($result),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolSchemas(): array
    {
        return collect($this->tools)->values()->map(fn (CopilotTool $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ])->all();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are the PayFlow Integration Copilot. You help a merchant's developer
            understand and debug their own PayFlow payment integration - things like "why
            didn't my order update", "what happened to payment X", "how does idempotency
            work".

            You have read-only tools to look up this merchant's own payments, payment
            event history, webhook delivery history, and to search PayFlow's own
            documentation. Only ever use these for the current merchant - you have no way
            to see any other merchant's data, and must never imply otherwise.

            You also have one WRITE tool, resendWebhook, which has a real side effect
            (it re-delivers a webhook to the merchant's own server). Never call it with
            confirmed=true unless the merchant has explicitly said yes/confirm/go ahead in
            this conversation, in direct response to you describing exactly what it will
            do. Always call it first with confirmed omitted (or false) to describe the
            proposed action, then wait for explicit confirmation before calling it again
            with confirmed=true.

            When diagnosing "payment succeeded but nothing happened on my end" style
            problems, check the webhook delivery history - a payment being `paid` in
            PayFlow while every delivery attempt shows a non-2xx response almost always
            means the problem is the merchant's own webhook endpoint, not PayFlow.
            PROMPT;
    }
}
