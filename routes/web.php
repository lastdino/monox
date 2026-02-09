<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix(config('monox.routes.prefix'))
    ->group(function () {
        Route::scopeBindings();

        // /monox/{department}/items など部門IDでスコープ
        Route::livewire('{department}/items', 'monox::items.index')->name('monox.items.index');
        Route::livewire('{department}/partners', 'monox::partners.index')->name('monox.partners.index');

        // 受注・出荷ダッシュボード
        Route::livewire('{department}/orders-shipments', 'monox::orders.dashboard')->name('monox.orders.dashboard');

        // 製造記録関連
        Route::livewire('{department}/production', 'monox::production.index')->name('monox.production.index');
        Route::livewire('{department}/production/{order}/worksheet/{process?}', 'monox::production.worksheet')->name('monox.production.worksheet');

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
