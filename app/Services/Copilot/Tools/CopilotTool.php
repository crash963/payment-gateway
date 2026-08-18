<?php

namespace App\Services\Copilot\Tools;

use App\Models\Merchant;

/**
 * A tool the copilot's model can call. $merchant is always the AUTHENTICATED merchant
 * from the current request - injected by CopilotService, never taken from the model's
 * tool-call arguments. This is the actual enforcement point for "the agent must never
 * see another merchant's data": even if the model is somehow induced to pass a
 * different merchant_id as an argument, every implementation here ignores that and
 * scopes its query to $merchant itself. Prompting the model to behave isn't a security
 * boundary; this is.
 */
interface CopilotTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema for the tool's arguments, in OpenAI function-calling format.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments  decoded from the model's tool call - untrusted input, validate before use
     * @return array<string, mixed> JSON-serializable result fed back to the model
     */
    public function execute(Merchant $merchant, array $arguments): array;
}
