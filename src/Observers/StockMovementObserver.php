<?php

namespace Lastdino\Monox\Observers;

use Lastdino\Monox\Models\StockMovement;
use Lastdino\Monox\Jobs\SyncStockToExternalSystemJob;

class StockMovementObserver
{
    /**
     * 在庫変動レコードが作成された直後に実行
     */
    public function created(StockMovement $stockMovement): void
    {
        // 品目で連動が有効か、かつ外部同期でないかチェック
        if ($stockMovement->item?->sync_to_procurement &&
            ! $stockMovement->is_external_sync &&
            in_array($stockMovement->type, ['in', 'out'])) {

            SyncStockToExternalSystemJob::dispatch($stockMovement);
        }
    }
}
