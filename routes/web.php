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
    Route::patch('/sheets/{sheet}', [SheetController::class, 'update'])->name('sheets.update');
    // Throttle: не более 60 запросов/мин на пользователя — защита от случайного
    // DoS, если пользователь триггернёт массовую перевыгрузку через экспорт.
    Route::get('/sheets/{sheet}/data', [SheetController::class, 'fetchData'])
        ->middleware('throttle:60,1')
        ->name('sheets.fetchData');
    Route::post('/sheets/{sheet}/data', [SheetController::class, 'updateData'])->name('sheets.updateData');
    Route::post('/sheets/{sheet}/insert-row', [SheetController::class, 'insertRow'])->name('sheets.insertRow');
    Route::post('/sheets/{sheet}/delete-row', [SheetController::class, 'deleteRow'])->name('sheets.deleteRow');

    // Импорт xlsx — любой залогиненный юзер. Импортёр становится owner новых листов
    // и может их редактировать. Видимость для других юзеров остаётся за админом.
    Route::post('/sheets/import-sheet', [SheetController::class, 'importSheet'])->name('sheets.importSheet');
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
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
