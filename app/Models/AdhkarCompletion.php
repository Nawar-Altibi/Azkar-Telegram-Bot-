<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdhkarCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'collection_group',
        'local_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
