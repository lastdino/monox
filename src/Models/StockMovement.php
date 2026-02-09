<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $table = 'monox_stock_movements';

    protected $fillable = [
        'item_id',
        'lot_id',
        'quantity',
        'type',
        'reason',
        'moved_at',
        'department_id',
        'production_annotation_value_id',
    ];

    public function productionAnnotationValue(): BelongsTo
    {
        return $this->belongsTo(ProductionAnnotationValue::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
        ];
    }
}
