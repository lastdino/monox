<?php

use Illuminate\Support\Facades\Route;

Route::middleware(array_merge(config('monox.routes.middleware', ['web']), ['monox.ensure-permissions']))
    ->prefix(config('monox.routes.prefix'))
    ->group(function () {
        Route::scopeBindings();

        // /monox/{department}/items など部門IDでスコープ
        Route::livewire('{department}/items', 'monox::items.index')->name('monox.items.index');
        Route::livewire('{department}/partners', 'monox::partners.index')->name('monox.partners.index');

        // 受注・出荷ダッシュボード
        Route::livewire('{department}/orders-shipments', 'monox::orders.dashboard')->name('monox.orders.dashboard');
        Route::livewire('{department}/orders/trace', 'monox::orders.trace')->name('monox.orders.trace');

        // 製造分析・ダッシュボード
        Route::livewire('{department}/analytics', 'monox::production.analytics')->name('monox.production.analytics');

        // 製造記録関連
        Route::livewire('{department}/production', 'monox::production.index')->name('monox.production.index');
        Route::livewire('{department}/production/{order}/worksheet', 'monox::production.worksheet')->name('monox.production.worksheet');
        Route::livewire('{department}/production/{order}/travel-sheet', 'monox::production.travel-sheet')->name('monox.production.travel-sheet');

        // 在庫・仕掛サマリー
        Route::livewire('{department}/inventory/lot-summary', 'monox::inventory.lot-summary')->name('monox.inventory.lot-summary');

        // 権限設定
        Route::livewire('{department}/permissions', 'monox::departments.permissions')->name('monox.departments.permissions')->middleware('auth');

        // 設定関連
        Route::livewire('processes/{process}/annotations', 'monox::processes.annotations')->name('monox.processes.annotations');

        // メディア配信
        Route::get('media/{media}', function (\Spatie\MediaLibrary\MediaCollections\Models\Media $media) {
            if (! file_exists($media->getPath())) {
                abort(404);
            }

            return response()->file($media->getPath());
        })->name('monox.media.show');
    });
