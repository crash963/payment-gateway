<?php

namespace App\Services\Copilot;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over OpenAI's Chat Completions REST endpoint - raw HTTP via Laravel's
 * Http client (see storage/docs for why: consistency with the rest of the app, and
 * Http::fake() works here exactly like it does for the fake-provider/webhook code,
 * with no SDK-specific mocking to learn).
 */
class OpenAiClient
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools  OpenAI function-calling tool schemas; omit for a plain (no-tools) call
     * @return array<string, mixed> the decoded JSON response body
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => config('services.openai.model'),
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::withToken(config('services.openai.api_key'))
            ->timeout((int) config('services.openai.timeout_seconds'))
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        // Let a non-2xx (bad key, rate limit, OpenAI outage) throw - CopilotService
        // doesn't attempt its own retry/fallback logic; the controller's exception
        // handling turns this into a clean error response instead of a silent wrong answer.
        $response->throw();

        return $response->json();
    }
}
