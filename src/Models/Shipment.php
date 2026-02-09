<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    use HasFactory;

    protected $table = 'monox_shipments';

    protected $fillable = [
        'department_id',
        'sales_order_id',
        'item_id',
        'lot_id',
        'shipment_number',
        'shipping_date',
        'quantity',
        'status',
        'note',
    ];

    protected $casts = [
        'shipping_date' => 'date',
        'quantity' => 'float',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
