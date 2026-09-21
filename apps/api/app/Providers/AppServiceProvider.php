<?php

namespace App\Providers;

use App\Support\TestingSafety;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $destructive = [
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'db:wipe',
            ];

            if (in_array($event->command, $destructive, true)) {
                TestingSafety::assertSafeForDestructiveDatabaseWork();
            }
        });
    }
}
