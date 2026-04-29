<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Чистим старые записи журнала аудита раз в сутки (в 03:15 по серверу).
        // Retention настраивается через AUDIT_LOG_RETENTION_DAYS в .env (по умолчанию 90).
        // Чтобы это реально запускалось — нужен системный cron на проде:
        //   * * * * * cd /var/www/excel && php artisan schedule:run >> /dev/null 2>&1
        $schedule->command('audit-log:cleanup')
                 ->dailyAt('03:15')
                 ->onOneServer()
                 ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
