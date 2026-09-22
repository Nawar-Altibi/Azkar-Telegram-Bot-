<?php

namespace App\Models;

use App\Enums\TelegramUpdateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'update_id',
        'chat_id',
        'payload',
        'status',
        'attempts',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'chat_id' => 'integer',
            'payload' => 'array',
            'status' => TelegramUpdateStatus::class,
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
