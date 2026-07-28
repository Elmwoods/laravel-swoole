<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsOnCallShift extends Model
{
    protected $fillable = [
        'assignee',
        'label',
        'starts_at',
        'ends_at',
        'recurrence',
        'days_of_week',
        'start_time',
        'end_time',
        'is_active',
        'reminded_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'days_of_week' => 'array',
            'is_active' => 'boolean',
            'reminded_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
