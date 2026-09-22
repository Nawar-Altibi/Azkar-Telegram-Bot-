<?php

namespace App\Models;

use App\Enums\AdhkarCategory;
use App\Enums\AdhkarVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdhkarCollection extends Model
{
    protected $fillable = [
        'key',
        'category',
        'variant',
        'title',
        'description',
        'active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'category' => AdhkarCategory::class,
            'variant' => AdhkarVariant::class,
            'active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(AdhkarItem::class, 'adhkar_collection_items', 'collection_id', 'item_id')
            ->withPivot('order')
            ->orderByPivot('order');
    }
}
