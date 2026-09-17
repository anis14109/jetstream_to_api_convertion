<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-client synchronization checkpoints.
 *
 *  - `last_pulled_cursor` is the highest journal revision delivered to the
 *    client. It is advanced by pull and is only used to validate ACKs.
 *  - `acknowledged_cursor` is the highest revision the client confirmed it has
 *    applied. It is advanced only by an explicit ACK and is what change-log
 *    pruning uses to stay safe for active clients.
 */
class SyncCursor extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'client_id',
        'last_pulled_cursor',
        'acknowledged_cursor',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pulled_cursor' => 'integer',
            'acknowledged_cursor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
