<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
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
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

use App\Http\Controllers\SheetController;
use App\Http\Controllers\SheetPermissionController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [SheetController::class, 'index'])->name('dashboard');
    Route::post('/sheets', [SheetController::class, 'store'])->name('sheets.store');
    Route::post('/sheets/import-sheet', [SheetController::class, 'importSheet'])->name('sheets.importSheet');
    Route::patch('/sheets/{sheet}', [SheetController::class, 'update'])->name('sheets.update');
    Route::delete('/sheets/{sheet}', [SheetController::class, 'destroy'])->name('sheets.destroy');
    Route::post('/sheets/{sheet}/data', [SheetController::class, 'updateData'])->name('sheets.updateData');
    Route::post('/sheets/{sheet}/insert-row', [SheetController::class, 'insertRow'])->name('sheets.insertRow');
    Route::post('/sheets/{sheet}/delete-row', [SheetController::class, 'deleteRow'])->name('sheets.deleteRow');
    
    Route::get('/sheets/{sheet}/permissions', [SheetPermissionController::class, 'index'])->name('sheets.permissions');
    Route::post('/sheets/{sheet}/permissions', [SheetPermissionController::class, 'update'])->name('sheets.permissions.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
