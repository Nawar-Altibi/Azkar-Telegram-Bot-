<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastDelivery extends Model
{
    protected $fillable = [
        'broadcast_id',
        'user_id',
        'chat_id',
        'status',
        'error_message',
        'attempted_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
