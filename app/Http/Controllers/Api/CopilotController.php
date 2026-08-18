<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CopilotChatRequest;
use App\Services\Copilot\CopilotService;
use Illuminate\Http\JsonResponse;

class CopilotController extends Controller
{
    /**
     * $request->user() (the authenticated merchant) is what CopilotService threads
     * through to every tool call - see CopilotTool for why that, not anything the
     * client/model could supply, is what scopes every lookup.
     */
    public function chat(CopilotChatRequest $request, CopilotService $copilot): JsonResponse
    {
        $result = $copilot->chat($request->user(), $request->validated()['messages']);

        return response()->json([
            'message' => $result['message'],
            // Client resends this whole array (plus their next message appended) on
            // the following turn - see CopilotService's class comment for why the
            // conversation is stateless server-side.
            'conversation' => $result['conversation'],
        ]);
    }
}
