<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Services\Copilot\CopilotService;
use App\Services\Copilot\Tools\GetPaymentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Http::fake() intercepts the OpenAI call the exact same way it does the
 * fake-provider/webhook self-calls elsewhere in this app - no OpenAI-specific mocking
 * library, and no automated test EVER makes a real (billed, non-deterministic) call to
 * OpenAI. See tests/TestCase's blanket Http::fake() default.
 */
class CopilotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function openAiTextResponse(string $content): array
    {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
        ];
    }

    private function openAiToolCallResponse(string $toolName, array $arguments, string $callId = 'call_1'): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => json_encode($arguments)],
                    ]],
                ],
            ]],
        ];
    }

    public function test_a_plain_response_with_no_tool_calls_is_returned_directly(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiTextResponse('Hello, how can I help?')),
        ]);

        $merchant = Merchant::factory()->create();
        $service = app(CopilotService::class);

        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello, how can I help?', $result['message']);
    }

    public function test_a_tool_call_is_executed_and_the_result_fed_back(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiToolCallResponse('getPayment', ['payment_id' => $payment->id]))
                ->push($this->openAiTextResponse('That payment is paid.')),
        ]);

        $service = app(CopilotService::class);
        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'what is the status?']]);

        $this->assertSame('That payment is paid.', $result['message']);

        // The tool result actually made it into the conversation sent back to OpenAI
        // on the second round - not just that a plausible-looking final answer appeared.
        $toolMessage = collect($result['conversation'])->firstWhere('role', 'tool');
        $this->assertNotNull($toolMessage);
        $this->assertSame($payment->id, json_decode($toolMessage['content'], true)['id']);
    }

    public function test_an_unknown_tool_name_returns_an_error_result_instead_of_crashing(): void
    {
        $merchant = Merchant::factory()->create();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiToolCallResponse('notARealTool', []))
                ->push($this->openAiTextResponse('ok')),
        ]);

        $service = app(CopilotService::class);
        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'hi']]);

        $toolMessage = collect($result['conversation'])->firstWhere('role', 'tool');
        $this->assertStringContainsString('Unknown tool', json_decode($toolMessage['content'], true)['error']);
    }

    /**
     * Regression test (user-reported): asked to "refund the whole payment", the model
     * searched documentation and asked "should I proceed?" across several turns before
     * finally admitting there's no tool for it - a confusing, trust-eroding loop for an
     * action that was never actually possible. Fixed at the system-prompt level: the
     * model is told its tools are the ONLY actions it can take, and to say so
     * immediately for anything else instead of implying the action might happen.
     */
    public function test_the_system_prompt_tells_the_model_its_tools_are_the_only_available_actions(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiTextResponse('ok')),
        ]);

        $merchant = Merchant::factory()->create();
        $service = app(CopilotService::class);

        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'hi']]);

        $systemMessage = collect($result['conversation'])->firstWhere('role', 'system');
        $this->assertStringContainsString('ONLY actions', $systemMessage['content']);
    }

    /**
     * Belt-and-suspenders with the system prompt fix above: even if the model ignores
     * (or has already lost track of) the system prompt's instruction, the tool result
     * fed back after an unsupported tool call - e.g. the model guessing at a
     * createRefund tool that doesn't exist - steers it away from asking for
     * confirmation right at the moment the mistake happens, not just at the start of
     * the conversation.
     */
    public function test_an_unknown_tool_error_instructs_the_model_not_to_ask_for_confirmation(): void
    {
        $merchant = Merchant::factory()->create();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openAiToolCallResponse('createRefund', ['payment_id' => 'x']))
                ->push($this->openAiTextResponse('ok')),
        ]);

        $service = app(CopilotService::class);
        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'refund this please']]);

        $toolMessage = collect($result['conversation'])->firstWhere('role', 'tool');
        $errorMessage = json_decode($toolMessage['content'], true)['error'];
        $this->assertStringContainsString("isn't something you can do", $errorMessage);
    }

    public function test_a_model_that_keeps_calling_tools_forever_is_bounded(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();

        // Always responds with another tool call, never a final answer.
        Http::fake([
            'api.openai.com/*' => Http::response(
                $this->openAiToolCallResponse('getPayment', ['payment_id' => $payment->id])
            ),
        ]);

        $service = app(CopilotService::class);
        $result = $service->chat($merchant, [['role' => 'user', 'content' => 'loop please']]);

        $this->assertNotNull($result['message']);
        Http::assertSentCount(5); // MAX_TOOL_ROUNDS, not an actual infinite loop
    }

    /**
     * Regression test (code review): chat() unconditionally prepended a fresh system
     * message every call, but the client is designed to resend the whole previous
     * 'conversation' (which already includes the system message from last turn) as
     * next turn's $messages - so the system prompt duplicated and accumulated by one
     * extra copy every turn instead of staying at exactly one.
     */
    public function test_a_second_turn_does_not_duplicate_the_system_message(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiTextResponse('ok')),
        ]);

        $merchant = Merchant::factory()->create();
        $service = app(CopilotService::class);

        $first = $service->chat($merchant, [['role' => 'user', 'content' => 'hi']]);

        // Exactly what the real client does: resend the whole returned conversation,
        // plus one new user message, as the next call's $messages.
        $secondTurnMessages = [...$first['conversation'], ['role' => 'user', 'content' => 'and then?']];
        $second = $service->chat($merchant, $secondTurnMessages);

        $systemMessages = collect($second['conversation'])->where('role', 'system');
        $this->assertCount(1, $systemMessages);
    }

    public function test_get_payment_tool_never_returns_another_merchants_payment(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->create();
        $payment = Payment::factory()->for($owner)->paid()->create();

        // The model could try to pass any merchant's payment id - the tool ignores
        // that and scopes strictly to $requester, which is what's injected here.
        $result = (new GetPaymentTool)->execute($requester, ['payment_id' => $payment->id]);

        $this->assertArrayHasKey('error', $result);
    }
}
