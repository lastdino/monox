<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lot extends Model
{
    use HasFactory;

    protected $table = 'monox_lots';

    protected $fillable = [
        'item_id',
        'lot_number',
        'expired_at',
        'department_id',
    ];
    /**
     * 配列/JSON 変換時に含めるアクセサ
     */
    protected $appends = ['full_label'];


    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }

    protected function casts(): array
    {
        return [
            'expired_at' => 'date',
        ];
    }

    public function scopeAvailable($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expired_at')
                ->orWhere('expired_at', '>=', now()->startOfDay());
        });
    }

    public function scopeWithStock($query)
    {
        return $query->withSum('stockMovements', 'quantity')
            ->whereHas('stockMovements', function ($q) {
                $q->selectRaw('sum(quantity)')
                    ->havingRaw('sum(quantity) > 0');
            });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getStockAtDate(\DateTimeInterface $date): float
    {
        return (float) $this->stockMovements()
            ->where('moved_at', '<=', $date)
            ->sum('quantity');
    }

    public function getCurrentStockAttribute(): float
    {
        if (array_key_exists('stock_movements_sum_quantity', $this->attributes)) {
            return (float) ($this->attributes['stock_movements_sum_quantity'] ?? 0);
        }

        return $this->getStockAtDate(now());
    }

    public function productionOrder(): HasOne
    {
        return $this->hasOne(ProductionOrder::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }
    public function getFullLabelAttribute(): string
    {
        return "{$this->item->name} : {$this->lot_number} ";
    }
}
