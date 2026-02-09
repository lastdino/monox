<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory;

    protected $table = 'monox_items';

    protected $fillable = [
        'code',
        'name',
        'type',
        'unit',
        'description',
        'department_id',
        'auto_inventory_update',
    ];

    protected $casts = [
        'auto_inventory_update' => 'boolean',
    ];

    public function getTypeLabelAttribute(): string
    {
        $type = (string) ($this->type ?? '');
        if ($type === '') {
            return '';
        }

        $department = $this->department;
        if (! $department) {
            return $type;
        }

        $types = $department->getItemTypes();
        foreach ($types as $t) {
            if (($t['value'] ?? null) === $type) {
                return (string) ($t['label'] ?? $type);
            }
        }

        return $type;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class)->orderBy('sort_order');
    }

    public function getCurrentStockAttribute(): float
    {
        return (float) $this->stockMovements()->sum('quantity');
    }

    /**
     * この品目を構成する子部品（BOM）
     */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'monox_boms', 'parent_item_id', 'child_item_id')
            ->withPivot('quantity', 'note')
            ->withTimestamps();
    }

    /**
     * この品目を使用している親部品（BOM逆引き）
     */
    public function parentItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'monox_boms', 'child_item_id', 'parent_item_id')
            ->withPivot('quantity', 'note')
            ->withTimestamps();
    }
}
