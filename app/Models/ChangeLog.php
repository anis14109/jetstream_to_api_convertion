<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'operation',
        'data',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The server revision number (the auto-increment journal id).
     */
    public function getRevisionAttribute(): int
    {
        return (int) $this->getAttribute('id');
    }
}
