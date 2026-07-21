<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id',
        'ip_address',
        'user_agent',
        'trusted',
        'is_new_ip',
        'is_new_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',
            'is_new_ip' => 'boolean',
            'is_new_user_agent' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
