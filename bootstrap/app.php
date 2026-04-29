<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // health: '/up' можно включить позже при настройке healthcheck'ов
        apiPrefix: 'api',
        then: function () {
            // Rate-limiter для api group (раньше жил в RouteServiceProvider).
            \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                    ->by($request->user()?->id ?: $request->ip());
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Глобальные web-middleware: HandleInertiaRequests должен быть в группе 'web',
        // чтобы Inertia мог делиться auth.user / isAdmin со всеми Vue-страницами.
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Aliases для роутов:
        //   middleware('role:admin')   — Spatie permission, защищает /users, /audit-log и т.д.
        //   middleware('permission:X') — Spatie permission per-action checks (не используется сейчас, но пусть будет)
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Чистим старые записи журнала аудита раз в сутки в 03:15.
        // Retention настраивается через AUDIT_LOG_RETENTION_DAYS (по умолчанию 90).
        // Cron на проде: * * * * * cd /var/www/excel && php artisan schedule:run >> /dev/null 2>&1
        $schedule->command('audit-log:cleanup')
                 ->dailyAt('03:15')
                 ->onOneServer()
                 ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Поведение по умолчанию L12 — нам пока ничего не надо переопределять.
        // Если когда-то добавим Sentry или кастомный reportable — здесь.
    })
    ->create();
