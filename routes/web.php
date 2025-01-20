<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExcelController;

Route::get('/', function () {
    return view('welcome-sugal');
});

Route::post('/', [ExcelController::class, 'procesar'])->name('procesar');
