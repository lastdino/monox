<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Process extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $table = 'monox_processes';

    protected $fillable = [
        'item_id',
        'name',
        'sort_order',
        'description',
        'standard_time_minutes',
        'template_image_path',
        'share_template_with_previous',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'standard_time_minutes' => 'float',
        'share_template_with_previous' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function annotationFields(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductionAnnotationField::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('template')
            ->singleFile()
            ->useDisk('local');
    }

    public function getTemplateMediaAttribute(): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $this->getFirstMedia('template');
    }
}
