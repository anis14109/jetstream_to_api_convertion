<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncCursor extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'client_id',
        'cursor',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
