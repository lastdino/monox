<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Equipment extends Model
{
    use HasFactory;

    protected $table = 'monox_equipments';

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            config('monox.models.department', Department::class),
            'monox_department_equipment',
            'equipment_id',
            'department_id'
        );
    }

    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(
            config('monox.models.process', Process::class),
            'monox_process_equipment',
            'equipment_id',
            'process_id'
        )->withTimestamps();
    }

    /**
     * asset-guard の資産と紐づけるためのヘルパー
     */
    public function asset(): ?\Lastdino\AssetGuard\Models\AssetGuardAsset
    {
        if (! class_exists('\Lastdino\AssetGuard\Models\AssetGuardAsset')) {
            return null;
        }

        // code をキーにして資産を特定する想定
        return \Lastdino\AssetGuard\Models\AssetGuardAsset::where('code', $this->code)->first();
    }
}
