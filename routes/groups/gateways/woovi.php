<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Gateway\WooviController;

Route::prefix('woovi')
    ->group(function () {
        // GET para teste (Woovi verifica se URL existe)
        Route::get('callback', function () {
            return response()->json(['status' => 'ok'], 200);
        });
        
        // POST para webhook real
        Route::post('callback', [WooviController::class, 'callbackMethod']);
    });