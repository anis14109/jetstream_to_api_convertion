<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\SyncServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    SyncServiceProvider::class,
];
