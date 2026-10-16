<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a user opened a wird: how far they got, when, and from where (reminder, direct reminder, keyboard).
 */
class ReadingSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'reminder_log_id',
        'collection_key',
        'phase',
        'source',
        'total',
        'furthest',
        'card_times',
        'local_hour',
        'local_weekday',
        'started_at',
        'last_seen_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'card_times' => 'array',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
