<?php

namespace App\Models;

use App\Models\Concerns\UsesUlid;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory;
    use SoftDeletes;
    use UsesUlid;

    /** @use HasFactory<StudentFactory> */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'notes',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Atomically bump the resource version. Returns true when the expected
     * version still matches (optimistic concurrency success).
     */
    public function advanceVersion(int $expectedVersion): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('version', $expectedVersion)
            ->update(['version' => $expectedVersion + 1]) === 1;
    }

    /**
     * The full snapshot of the resource transmitted to clients.
     *
     * @return array<string, mixed>
     */
    public function toSyncSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
