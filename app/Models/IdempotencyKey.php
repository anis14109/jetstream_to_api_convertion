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
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
