<?php

namespace Lastdino\Monox\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lastdino\Monox\Models\Bom;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\ItemType;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Partner;
use Lastdino\Monox\Models\StockMovement;

trait HasMonoxRelations
{
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'department_id');
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class, 'department_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'department_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'department_id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class, 'department_id');
    }

    public function itemTypes(): HasMany
    {
        return $this->hasMany(ItemType::class)->orderBy('sort_order');
    }

    public function getItemTypes(): array
    {
        $types = $this->itemTypes;

        if ($types->isEmpty()) {
            return [
                ['value' => 'part', 'label' => '部品'],
                ['value' => 'product', 'label' => '製品'],
            ];
        }

        return $types->map(fn ($type) => [
            'value' => $type->value,
            'label' => $type->label,
        ])->toArray();
    }
}
