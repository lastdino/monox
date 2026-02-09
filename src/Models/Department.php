<?php

namespace Lastdino\Monox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Lastdino\Monox\Traits\HasMonoxRelations;

class Department extends Model
{
    use HasFactory, HasMonoxRelations;

    protected $table = 'monox_departments';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

}
