<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionAnnotationField extends Model
{
    use HasFactory;

    protected $table = 'monox_production_annotation_fields';

    protected $fillable = [
        'process_id',
        'field_key',
        'label',
        'type',
        'x_percent',
        'y_percent',
        'width_percent',
        'height_percent',
        'target_value',
        'min_value',
        'max_value',
        'is_optional',
        'linked_item_id',
        'related_field_id',
    ];

    protected $casts = [
        'x_percent' => 'float',
        'y_percent' => 'float',
        'width_percent' => 'float',
        'height_percent' => 'float',
        'target_value' => 'float',
        'min_value' => 'float',
        'max_value' => 'float',
        'is_optional' => 'boolean',
        'linked_item_id' => 'integer',
        'related_field_id' => 'integer',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'linked_item_id');
    }

    public function relatedField(): BelongsTo
    {
        return $this->belongsTo(ProductionAnnotationField::class, 'related_field_id');
    }
}
