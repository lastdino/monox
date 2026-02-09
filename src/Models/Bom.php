<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bom extends Model
{
    protected $table = 'monox_boms';

    protected $fillable = [
        'parent_item_id',
        'child_item_id',
        'quantity',
        'note',
        'department_id',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }
}
