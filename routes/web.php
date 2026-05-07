<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SheetAuditLogController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

use App\Http\Controllers\SheetController;
use App\Http\Controllers\SheetPermissionController;
use App\Http\Controllers\UserController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [SheetController::class, 'index'])->name('dashboard');
    Route::patch('/sheets/{sheet}', [SheetController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('sheets.update');
    // Throttle: не более 60 запросов/мин на пользователя — защита от случайного
    // DoS, если пользователь триггернёт массовую перевыгрузку через экспорт.
    Route::get('/sheets/{sheet}/data', [SheetController::class, 'fetchData'])
        ->middleware('throttle:60,1')
        ->name('sheets.fetchData');
    // Write-операции: 300/мин на пользователя. Это в 5 раз больше debounce'а
    // фронта (он шлёт максимум один POST/секунду при активном вводе) — норма
    // не упирается, но злонамеренный/багнутый клиент не задудосит сервер.
    Route::post('/sheets/{sheet}/data', [SheetController::class, 'updateData'])
        ->middleware('throttle:300,1')
        ->name('sheets.updateData');
    Route::post('/sheets/{sheet}/insert-row', [SheetController::class, 'insertRow'])
        ->middleware('throttle:60,1')
        ->name('sheets.insertRow');
    Route::post('/sheets/{sheet}/delete-row', [SheetController::class, 'deleteRow'])
        ->middleware('throttle:60,1')
        ->name('sheets.deleteRow');

    // Отправка листа по почте через подключенный Gmail юзера.
    // Rate limit 30/час — защита от спама и от исчерпания Gmail-квоты юзера.
    Route::post('/sheets/{sheet}/email', [SheetController::class, 'email'])
        ->middleware('throttle:30,60')
        ->name('sheets.email');

    // Импорт xlsx — любой залогиненный юзер. Импортёр становится owner новых листов
    // и может их редактировать. Видимость для других юзеров остаётся за админом.
    // Throttle помягче (10/мин) — импорт тяжёлый.
    Route::post('/sheets/import-sheet', [SheetController::class, 'importSheet'])
        ->middleware('throttle:10,1')
        ->name('sheets.importSheet');
});

// Только админ: создание пустого листа, удаление, права, управление пользователями.
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::post('/sheets', [SheetController::class, 'store'])->name('sheets.store');
    Route::delete('/sheets/{sheet}', [SheetController::class, 'destroy'])->name('sheets.destroy');

    Route::get('/sheets/{sheet}/permissions', [SheetPermissionController::class, 'index'])->name('sheets.permissions');
    Route::post('/sheets/{sheet}/permissions', [SheetPermissionController::class, 'update'])->name('sheets.permissions.update');
    Route::post('/sheets-permissions/bulk', [SheetPermissionController::class, 'bulk'])->name('sheets.permissions.bulk');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/audit-log', [SheetAuditLogController::class, 'index'])->name('audit-log.index');
    Route::delete('/audit-log', [SheetAuditLogController::class, 'clear'])->name('audit-log.clear');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Самоудаление аккаунта запрещено: пользователи не должны удалять себя.
    // Если нужно удалить юзера — это делает админ через /users.

    // Подключение Gmail-аккаунта юзера для отправки писем от его имени.
    Route::get('/auth/google/connect',     [\App\Http\Controllers\Auth\GoogleAuthController::class, 'connect'])->name('google.connect');
    Route::get('/auth/google/callback',    [\App\Http\Controllers\Auth\GoogleAuthController::class, 'callback'])->name('google.callback');
    Route::delete('/auth/google/disconnect',[\App\Http\Controllers\Auth\GoogleAuthController::class, 'disconnect'])->name('google.disconnect');
});

require __DIR__.'/auth.php';
