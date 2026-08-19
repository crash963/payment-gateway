<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_event_id' => $this->payment_event_id,
            'url' => $this->url,
            'attempt' => $this->attempt,
            'http_status' => $this->http_status,
            'response' => $this->response,
            'successful' => $this->successful,
            'sent_at' => $this->sent_at->toIso8601String(),
        ];
    }
}
