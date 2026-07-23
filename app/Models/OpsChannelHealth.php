<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsChannelHealth extends Model
{
    protected $table = 'ops_channel_health';

    protected $fillable = [
        'channel',
        'status',
        'consecutive_failures',
        'last_ok_at',
        'last_checked_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'last_ok_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
