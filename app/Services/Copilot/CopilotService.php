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
     * @param  array<int, array<string, mixed>>  $messages  prior conversation - may or
     *                                                      may not already contain a
     *                                                      system message (see below)
     * @return array{message: ?string, conversation: array<int, array<string, mixed>>}
     */
    public function chat(Merchant $merchant, array $messages): array
    {
        // Found in code review: the client is designed to resend the full 'conversation'
        // this method returns (see CopilotController) - which already includes the
        // system message THIS call added - as next turn's $messages. Unconditionally
        // prepending another one here meant turn 2 sent 2 system messages, turn 3 sent
        // 3, and so on, unbounded. Stripping any incoming system message first, then
        // adding exactly one fresh one, makes this correct for both a brand new
        // conversation (no system message yet) and a resumed one (one already present).
        $priorMessages = array_values(array_filter(
            $messages,
            fn (array $message) => ($message['role'] ?? null) !== 'system'
        ));

        $conversation = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ...$priorMessages,
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

        // The message itself instructs the model how to react, not just that it
        // failed - a bare "Unknown tool" invites the model to keep trying (search
        // docs, ask "should I proceed?") instead of telling the merchant directly
        // that this isn't something it can do. Belt-and-suspenders with the system
        // prompt's "only your five tools exist" rule: this fires exactly at the
        // moment the model attempts the unsupported action, not just at the start
        // of the conversation where the instruction is easier to lose track of.
        $result = $tool
            ? $tool->execute($merchant, $arguments)
            : ['error' => "Unknown tool: {$name}. This action isn't available - don't ask for confirmation or imply it might be possible after gathering more information. Tell the merchant directly that this isn't something you can do, and point them to the API or dashboard instead."];

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

            Your five tools above are the ONLY actions you're able to take - there is no
            tool to create or modify a payment, issue a refund, change merchant settings,
            or do anything else. If asked to do something outside that list, say so
            clearly and immediately - do not search documentation or ask "should I
            proceed?" as if the action might still be possible once you gather more
            information. Only resendWebhook ever warrants asking for confirmation;
            everything else you can't do gets a direct "I can't do that" answer, not a
            multi-turn back-and-forth that ends the same way anyway.

            When diagnosing "payment succeeded but nothing happened on my end" style
            problems, check the webhook delivery history - a payment being `paid` in
            PayFlow while every delivery attempt shows a non-2xx response almost always
            means the problem is the merchant's own webhook endpoint, not PayFlow.
            PROMPT;
    }
}
