<?php

namespace App\Models;

use App\Enums\AdhkarCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdhkarItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'category',
        'title',
        'arabic_text',
        'repetitions_count',
        'repetitions_label',
        'timing',
        'virtue',
        'source_text',
        'source_name',
        'hadith_number',
        'hadith_grade',
        'narrator',
        'guidance_note',
        'notes',
        'order',
        'active',
        'content_version',
        'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'category' => AdhkarCategory::class,
            'repetitions_count' => 'integer',
            'order' => 'integer',
            'active' => 'boolean',
            'content_version' => 'integer',
        ];
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(AdhkarCollection::class, 'adhkar_collection_items', 'item_id', 'collection_id')
            ->withPivot('order');
    }
}
