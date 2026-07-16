<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsAlertEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'action',
        'actor',
        'from_status',
        'to_status',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class, 'alert_id');
    }
}
