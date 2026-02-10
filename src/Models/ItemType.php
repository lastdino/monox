<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemType extends Model
{
    use HasFactory;

    protected $table = 'monox_item_types';

    protected $fillable = [
        'department_id',
        'value',
        'label',
        'sort_order',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }
}
