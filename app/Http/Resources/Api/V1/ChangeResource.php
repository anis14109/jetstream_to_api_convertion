<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ChangeLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single entry of the change journal as delivered to sync clients.
 *
 * @mixin ChangeLog
 */
class ChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'revision' => (int) $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'operation' => $this->operation,
            'version' => $this->data['version'] ?? null,
            'data' => $this->data,
            'server_time' => $this->created_at?->toISOString(),
        ];
    }
}
