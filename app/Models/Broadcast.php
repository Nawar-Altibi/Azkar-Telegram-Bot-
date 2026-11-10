<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    protected $fillable = [
        'telegram_update_id',
        'requested_by_telegram_id',
        'message',
        'max_user_id',
        'last_user_id',
        'target_count',
        'sent_count',
        'failed_count',
        'status',
        'retry_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_update_id' => 'integer',
            'requested_by_telegram_id' => 'integer',
            'max_user_id' => 'integer',
            'last_user_id' => 'integer',
            'target_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'retry_at' => 'datetime',
        ];
    }
}
