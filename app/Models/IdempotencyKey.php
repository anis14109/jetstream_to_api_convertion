<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'key_hash',
        'request_hash',
        'entity_type',
        'entity_id',
        'operation',
        'response_code',
        'response_json',
        'completed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_json' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A reservation that has not yet produced a stored response. A concurrent
     * request holding the same operation id must not execute the operation.
     */
    public function isPending(): bool
    {
        return $this->completed_at === null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
