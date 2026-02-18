<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->getStockAtDate(now());
    }

    public function productionOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }
}
