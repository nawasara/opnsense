<?php

namespace Nawasara\Opnsense;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\Opnsense\Jobs\ExpireVpnAccountsJob;
use Nawasara\Opnsense\Jobs\SyncOpnsenseStatusJob;
use Nawasara\Opnsense\Jobs\SyncVpnAccountsJob;
use Nawasara\Opnsense\Services\OPNsenseClient;
use Symfony\Component\Finder\Finder;

class OpnsenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-opnsense');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Guarded — Laravel's view:cache crashes on missing registered paths.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-opnsense');
        }

        $this->registerLivewire();
        $this->registerSchedule();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-opnsense.php', 'nawasara-opnsense');
        $this->app->singleton(OPNsenseClient::class, fn () => new OPNsenseClient());
    }

    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            if (! config('nawasara-opnsense.scheduler.enabled', true)) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);

            // Sync status semua appliance tiap `sync_interval` menit.
            // Dispatch job langsung lewat $schedule->call() — bukan
            // $schedule->command() — karena command paket tidak selalu
            // surface di Artisan kernel saat boot belakangan.
            $interval = max(1, (int) config('nawasara-opnsense.sync_interval', 2));

            $schedule->call(fn () => SyncOpnsenseStatusJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-opnsense:sync-status')
                ->cron("*/{$interval} * * * *")
                ->withoutOverlapping(10);

            // Daily VPN account lifecycle sweep — disable expired peers, flag
            // expiring-soon, hard-delete beyond grace. Runs at 02:15.
            $schedule->call(fn () => ExpireVpnAccountsJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-opnsense:expire-vpn')
                ->dailyAt('02:15')
                ->withoutOverlapping(10);

            // Reconcile the account list with the peers that actually exist on
            // the firewall: import peers created directly in OPNsense, and mark
            // records whose peer was removed there. Hourly is enough — peers are
            // not created often, and the VPN page has a manual sync button.
            // Minutes between runs; clamped to a valid cron step (1..59) so a
            // stray config value can't produce an invalid expression.
            $vpnInterval = max(5, min(59, (int) config('nawasara-opnsense.vpn.sync_interval', 30)));
            $schedule->call(fn () => SyncVpnAccountsJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-opnsense:sync-vpn-accounts')
                ->cron("*/{$vpnInterval} * * * *")
                ->withoutOverlapping(15);
        });
    }

    protected function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Opnsense\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-opnsense.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
