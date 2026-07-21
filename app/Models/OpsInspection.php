<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsInspection extends Model
{
    protected $fillable = [
        'admin_user_id',
        'admin_email',
        'type',
        'trigger',
        'status',
        'summary',
        'checks',
        'duration_ms',
        'started_at',
        'finished_at',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'checks' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
