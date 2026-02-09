<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Partner extends Model
{
    use HasFactory;

    protected $table = 'monox_partners';

    protected $fillable = [
        'code',
        'name',
        'type',
        'email',
        'phone',
        'address',
        'department_id',
    ];
    public function department(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.department', Department::class));
    }
}
