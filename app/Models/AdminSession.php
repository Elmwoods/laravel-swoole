<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSession extends Model
{
    protected $fillable = [
        'admin_user_id',
        'session_token_hash',
        'label',
        'ip_address',
        'user_agent',
        'last_activity_at',
        'revoked_at',
    ];

    protected $hidden = [
        'session_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
