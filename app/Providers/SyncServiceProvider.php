<?php

namespace App\Providers;

use App\Sync\Contracts\SyncResourceHandler;
use App\Sync\Handlers\StudentSyncHandler;
use App\Sync\SyncResourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the synchronizable resources with the generic sync engine.
 *
 * Adding a resource: write a {@see SyncResourceHandler}
 * and register it below. Nothing else in the sync engine changes.
 */
class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncResourceRegistry::class, function ($app): SyncResourceRegistry {
            $registry = new SyncResourceRegistry;

            $registry->register($app->make(StudentSyncHandler::class));

            return $registry;
        });
    }
}
