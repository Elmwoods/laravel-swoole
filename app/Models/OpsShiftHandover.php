<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsShiftHandover extends Model
{
    protected $fillable = [
        'from_assignee',
        'to_assignee',
        'note',
        'open_alert_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'open_alert_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
