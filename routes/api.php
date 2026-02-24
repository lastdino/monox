<?php

use Illuminate\Support\Facades\Route;
use Lastdino\Monox\Http\Controllers\Api\InspectionController;
use Lastdino\Monox\Http\Controllers\Api\InventoryController;

Route::prefix('api/monox/v1')->middleware(['monox.api-key'])->group(function () {
    Route::post('/inventory/sync', [InventoryController::class, 'sync']);
    Route::post('/inspection/sync', [InspectionController::class, 'sync']);
});
