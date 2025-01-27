<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExcelController;

Route::get('/', function () {
    return view('welcome-sugal');
});

Route::post('/', [ExcelController::class, 'procesar'])->name('procesar');
Route::get('/tranciti/login', [ExcelController::class, 'login'])->name('login');

Route::get('/tranciti/ubicaciones', [ExcelController::class, 'tranciti_validate_spot']);
Route::post('/tranciti/example-post', [ExcelController::class, 'postExample']);
