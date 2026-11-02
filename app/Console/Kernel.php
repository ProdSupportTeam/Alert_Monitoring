<?php

namespace App\Console;

use App\Console\Commands\CheckFailedOrders;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CheckFailedOrders::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Matches OrderController::WINDOW_MINUTES - keep the two in step or the
        // scan will either miss orders (cron slower) or double-count them.
        $schedule->command('alerts:failed-orders')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->timezone('Asia/Kolkata');
    }
}
