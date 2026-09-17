<?php

namespace App\Sync;

use App\Sync\Contracts\SyncResourceHandler;

/**
 * Maps a synchronization `entity_type` to the handler that knows how to apply
 * it. Adding a new synchronizable resource means writing a handler and
 * registering it here; the engine itself does not change.
 */
final class SyncResourceRegistry
{
    /** @var array<string, SyncResourceHandler> */
    private array $handlers = [];

    public function register(SyncResourceHandler $handler): void
    {
        $this->handlers[$handler->entityType()] = $handler;
    }

    public function has(string $entityType): bool
    {
        return isset($this->handlers[$entityType]);
    }

    public function get(string $entityType): SyncResourceHandler
    {
        return $this->handlers[$entityType]
            ?? throw new \InvalidArgumentException("No sync handler registered for entity type [{$entityType}].");
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @return array<string, SyncResourceHandler>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }
}
