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
        'target_quantity',
        'status',
        'note',
    ];

    protected $casts = [
        'target_quantity' => 'float',
    ];

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
