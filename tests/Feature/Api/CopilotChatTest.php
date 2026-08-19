<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CopilotChatTest extends TestCase
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

    public function test_a_merchant_can_chat_with_the_copilot(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiTextResponse('Hello!')),
        ]);

        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/copilot/chat', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], ['Authorization' => 'Bearer test-key']);

        $response->assertOk();
        $response->assertJsonPath('message', 'Hello!');
    }

    public function test_chatting_with_the_copilot_requires_authentication(): void
    {
        $response = $this->postJson('/api/copilot/chat', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);

        $response->assertStatus(401);
    }

    public function test_an_empty_messages_array_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/copilot/chat', ['messages' => []], [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Regression test (code review): OpenAiClient::chat()'s own comment claimed "the
     * controller's exception handling turns this into a clean error response" - but
     * nothing actually caught the RequestException $response->throw() raises on a
     * non-2xx OpenAI response, so a bad API key / rate limit / outage fell through to
     * a raw 500 instead.
     */
    public function test_an_upstream_openai_failure_is_a_clean_502_not_a_500(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'invalid_api_key']], 401),
        ]);

        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/copilot/chat', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], ['Authorization' => 'Bearer test-key']);

        $response->assertStatus(502);
        $response->assertJsonPath('error.code', 'copilot_upstream_error');
    }

    public function test_message_content_survives_validation_and_reaches_the_model(): void
    {
        // Regression test for the bug found live: FormRequest::validated() was
        // silently stripping `content` because only `messages.*.role` had a rule -
        // OpenAI then rejected the request with "expected a string, got null".
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAiTextResponse('ok')),
        ]);

        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $this->postJson('/api/copilot/chat', [
            'messages' => [['role' => 'user', 'content' => 'a specific question']],
        ], ['Authorization' => 'Bearer test-key']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');

            return $userMessage['content'] === 'a specific question';
        });
    }
}
