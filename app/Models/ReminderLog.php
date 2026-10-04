<?php

namespace App\Models;

use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reminder_type',
        'collection_key',
        'scheduled_local_date',
        'scheduled_for',
        'status',
        'telegram_message_id',
        'sent_at',
        'reading_started_at',
        'follow_up_sent_at',
        'completed_at',
        'error_message',
        'retry_count',
        'dispatch_attempts',
        'last_dispatch_attempt_at',
        'content_version_map',
        'content_snapshot_hash',
    ];

    protected function casts(): array
    {
        return [
            'reminder_type' => ReminderType::class,
            'status' => ReminderLogStatus::class,
            'scheduled_local_date' => 'date',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'reading_started_at' => 'datetime',
            'follow_up_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_dispatch_attempt_at' => 'datetime',
            'telegram_message_id' => 'array',
            'content_version_map' => 'array',
            'retry_count' => 'integer',
            'dispatch_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
