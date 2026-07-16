<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsAlertEvaluation extends Model
{
    protected $fillable = [
        'trigger',
        'status',
        'detected_count',
        'auto_resolved_count',
        'started_at',
        'finished_at',
        'duration_ms',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
