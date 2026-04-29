<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Отключаем <link rel="preload" as="style"> ТОЛЬКО для CSS — Laravel-Vite
        // ставит их параллельно с <link rel="stylesheet"> на тот же URL, браузер
        // использует stylesheet, а preload помечает как «unused» и засоряет
        // консоль предупреждениями. Для JS-чанков modulepreload оставляем —
        // он реально ускоряет параллельную загрузку зависимостей (lodash, ag-grid, etc.).
        Vite::usePreloadTagAttributes(function ($src, $url) {
            return preg_match('/\.css($|\?)/', $url) ? false : [];
        });
    }
}
