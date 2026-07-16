<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsAlertRule extends Model
{
    protected $fillable = [
        'key',
        'name',
        'source',
        'metric',
        'operator',
        'warning_threshold',
        'critical_threshold',
        'unit',
        'is_active',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'warning_threshold' => 'float',
            'critical_threshold' => 'float',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
