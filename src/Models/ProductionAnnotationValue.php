<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductionAnnotationValue extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $table = 'monox_production_annotation_values';

    protected $fillable = [
        'production_record_id',
        'field_id',
        'value',
        'note',
        'is_within_tolerance',
        'lot_id',
        'quantity',
    ];

    protected $casts = [
        'is_within_tolerance' => 'boolean',
        'lot_id' => 'integer',
        'quantity' => 'float',
    ];

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductionAnnotationField::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'production_annotation_value_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->singleFile()
            ->useDisk('local');
    }
}
