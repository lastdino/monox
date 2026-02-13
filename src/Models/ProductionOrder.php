<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $table = 'monox_production_orders';

    protected $fillable = [
        'department_id',
        'item_id',
        'lot_id',
        'parent_order_id',
        'target_quantity',
        'status',
        'note',
    ];

    protected $casts = [
        'target_quantity' => 'float',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'parent_order_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProductionOrder::class, 'parent_order_id');
    }

    public function getAllRelatedOrders()
    {
        // 親を辿る
        $ancestor = $this;
        while ($ancestor->parent) {
            $ancestor = $ancestor->parent;
        }

        // 最古の親から全ての子孫を取得する
        return $this->collectDescendants($ancestor);
    }

    private function collectDescendants($order)
    {
        $orders = collect([$order]);
        foreach ($order->children as $child) {
            $orders = $orders->merge($this->collectDescendants($child));
        }

        return $orders;
    }

    public function getRecordForProcess(int $processId)
    {
        $record = $this->productionRecords()
            ->where('process_id', $processId)
            ->first();

        if ($record) {
            return $record;
        }

        return $this->getParentRecord($processId);
    }

    private function getParentRecord(int $processId)
    {
        if (! $this->parent_order_id) {
            return null;
        }

        $parent = $this->parent;
        $record = $parent->productionRecords()
            ->where('process_id', $processId)
            ->where('status', 'completed')
            ->first();

        if ($record) {
            return $record;
        }

        return $parent->getParentRecord($processId);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function currentProcess()
    {
        // 進行中のレコードがあればそれを優先
        $inProgress = $this->productionRecords()
            ->where('status', 'in_progress')
            ->with('process')
            ->first();

        if ($inProgress) {
            return $inProgress->process;
        }

        // 進行中がなければ、最後に完了した工程の次を探す
        $lastCompleted = $this->productionRecords()
            ->where('status', 'completed')
            ->with('process')
            ->get()
            ->sortByDesc(fn ($record) => $record->process->sort_order)
            ->first();

        if ($lastCompleted) {
            $next = $this->item->processes()
                ->where('sort_order', '>', $lastCompleted->process->sort_order)
                ->orderBy('sort_order')
                ->first();

            return $next ?: $lastCompleted->process;
        }

        // 何もなければ最初の工程
        return $this->item->processes()
            ->orderBy('sort_order')
            ->first();
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }
}
