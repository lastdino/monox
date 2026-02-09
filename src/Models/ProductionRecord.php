<?php

namespace Lastdino\Monox\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionRecord extends Model
{
    use HasFactory;

    protected $table = 'monox_production_records';

    protected $fillable = [
        'production_order_id',
        'process_id',
        'worker_id',
        'status',
        'input_quantity',
        'good_quantity',
        'defective_quantity',
        'setup_started_at',
        'setup_finished_at',
        'work_started_at',
        'work_finished_at',
        'paused_at',
        'total_paused_seconds',
        'note',
    ];
    protected $casts = [
        'setup_started_at' => 'datetime',
        'setup_finished_at' => 'datetime',
        'work_started_at' => 'datetime',
        'work_finished_at' => 'datetime',
        'paused_at' => 'datetime',
        'total_paused_seconds' => 'integer',
        'input_quantity' => 'float',
        'good_quantity' => 'float',
        'defective_quantity' => 'float',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function annotationValues(): HasMany
    {
        return $this->hasMany(ProductionAnnotationValue::class);
    }
}
