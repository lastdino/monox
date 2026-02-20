<?php

namespace Lastdino\Monox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Lastdino\Monox\Models\StockMovement;

class SyncStockToExternalSystemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public StockMovement $stockMovement
    ) {}

    public function handle(): void
    {
        // 外部システムからの同期による変動の場合は、送り返さない（無限ループ防止）
        if ($this->stockMovement->is_external_sync) {
            return;
        }

        $config = config('monox.matex');
        $item = $this->stockMovement->item;

        // 品目個別の設定をチェック
        if (! ($item?->sync_to_procurement)) {
            return;
        }

        $lot = $this->stockMovement->lot;

        // 入庫(in)か出庫(out)かに応じてエンドポイントを切り替え
        $action = $this->stockMovement->type === 'in' ? 'stock-in' : 'stock-out';
        $apiUrl = rtrim($config['url'], '/') . "/api/matex/{$action}";
        $apiKey = $config['api_key'];

        // 外部 API が期待するデータ構造に整形
        $payload = [
            'sku'    => $item->code,
            'lot_no' => $lot?->lot_number,
            'qty'    => abs($this->stockMovement->quantity), // 数量は正の数として送信
            'reason' => $this->stockMovement->reason ?: 'Monox からの自動同期',
        ];

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
            'Accept' => 'application/json',
        ])->post($apiUrl, $payload);

        if ($response->failed()) {
            logger()->error("外部システムへの在庫同期に失敗しました ({$action})", [
                'movement_id' => $this->stockMovement->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \Exception('Inventory Sync Failed: ' . $response->status());
        }
    }
}
