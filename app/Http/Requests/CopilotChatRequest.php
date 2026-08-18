<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CopilotChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately loose beyond `role` being present: `content`/`tool_calls` shape
     * varies legitimately by role (a plain user turn vs. an assistant turn that made
     * tool calls vs. a tool-result turn), and this endpoint is designed to be fed back
     * exactly what CopilotController previously returned in `conversation` - validating
     * that shape strictly here would mean keeping two definitions of "what a message
     * looks like" in sync for no real safety benefit.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,tool,system'],
            // validated() only returns fields that have a rule of their own - without
            // this, `content` (and the other fields below) would be silently stripped
            // from every message before CopilotService ever saw them, even though the
            // client sent them. Found this the hard way testing live against OpenAI:
            // "Invalid value for 'content': expected a string, got null."
            'messages.*.content' => ['nullable', 'string'],
            'messages.*.tool_call_id' => ['nullable', 'string'],
            'messages.*.tool_calls' => ['nullable', 'array'],
        ];
    }
}
