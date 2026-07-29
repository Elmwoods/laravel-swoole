<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsAlertRuleChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rule_key',
        'field',
        'old_value',
        'new_value',
        'actor',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
