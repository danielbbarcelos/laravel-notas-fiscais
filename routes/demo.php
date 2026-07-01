<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->prefix('notas-fiscais/demo')
    ->name('notas-fiscais.demo.')
    ->group(function () {
        Route::get('/', [DemoController::class, 'index'])->name('index');
        Route::post('/emitir', [DemoController::class, 'emitir'])->name('emitir');
    });
