<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsAlertNote extends Model
{
    protected $fillable = [
        'alert_id',
        'admin_user_id',
        'author',
        'body',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
