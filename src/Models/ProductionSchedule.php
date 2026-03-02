<?php

namespace Lastdino\Monox\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionSchedule extends Model
{
    use HasFactory;

    protected $table = 'monox_production_schedules';

    protected $fillable = [
        'production_order_id',
        'process_id',
        'worker_id',
        'equipment_id',
        'scheduled_start_at',
        'scheduled_end_at',
        'sort_order',
        'status',
        'note',
    ];

    protected $casts = [
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'sort_order' => 'integer',
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

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(config('monox.models.equipment', Equipment::class));
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class, 'production_schedule_id');
    }
}
