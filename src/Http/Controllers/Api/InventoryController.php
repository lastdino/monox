<?php

namespace Lastdino\Monox\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\StockMovement;

class InventoryController extends Controller
{
    public function sync(Request $request)
    {
        // 1. バリデーション
        $validated = $request->validate([
            'sku' => 'required|exists:monox_items,code',
            'lot_no' => 'nullable|string',
            'qty' => 'required|numeric',
            'type' => 'required|in:in,out',
            'reason' => 'nullable|string',
        ]);

        $item = Item::where('code', $validated['sku'])->firstOrFail();

        // 2. ロットの取得または作成
        $lotId = null;
        $isNewLot = false;
        if (! empty($validated['lot_no'])) {
            $lot = Lot::firstOrCreate(
                ['item_id' => $item->id, 'lot_number' => $validated['lot_no']],
                ['department_id' => $item->department_id]
            );
            $lotId = $lot->id;
            $isNewLot = $lot->wasRecentlyCreated;
        }

        // 3. 在庫変動の記録
        // 注意: monox 内部では出庫は負の値として保持するため調整
        $quantity = $validated['type'] === 'out' ? -abs($validated['qty']) : abs($validated['qty']);

        $movement = StockMovement::create([
            'item_id' => $item->id,
            'lot_id' => $lotId,
            'quantity' => $quantity,
            'type' => $validated['type'],
            'reason' => ($validated['reason'] ?? null) ?: 'matex からの同期',
            'is_external_sync' => true,
            'moved_at' => now(),
            'department_id' => $item->department_id,
        ]);

        // 4. 製造指図の自動発行
        // 条件: 入庫であること、新規ロットであること、工程が登録されていること
        if ($validated['type'] === 'in' && $isNewLot && $item->processes()->exists()) {
            \Lastdino\Monox\Models\ProductionOrder::create([
                'department_id' => $item->department_id,
                'item_id' => $item->id,
                'lot_id' => $lotId,
                'target_quantity' => abs($validated['qty']),
                'status' => 'pending',
                'note' => 'API入庫による自動発行',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Inventory updated in Monox',
            'movement_id' => $movement->id,
        ]);
    }
}
