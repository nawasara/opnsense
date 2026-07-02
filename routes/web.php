<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Opnsense\Livewire\Monitor\Index as MonitorIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-opnsense')->group(function () {
    Route::get('monitor', MonitorIndex::class)
        ->middleware(PermissionMiddleware::using('opnsense.monitor.view'))
        ->name('nawasara-opnsense.monitor');
});
