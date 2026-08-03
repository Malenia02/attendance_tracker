<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->notification_id,
            'type' => $this->notification_type,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'action_url' => $this->action_url,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
