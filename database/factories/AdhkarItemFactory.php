<?php

namespace Database\Factories;

use App\Enums\AdhkarCategory;
use App\Models\AdhkarItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdhkarItem>
 */
class AdhkarItemFactory extends Factory
{
    protected $model = AdhkarItem::class;

    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'key' => 'test.'.fake()->unique()->numerify('###'),
            'category' => AdhkarCategory::Morning,
            'title' => 'ذكر تجريبي',
            'arabic_text' => $text,
            'repetitions_count' => 1,
            'repetitions_label' => 'مرة واحدة',
            'timing' => null,
            'virtue' => null,
            'source_text' => null,
            'source_name' => 'صحيح البخاري',
            'hadith_number' => '1',
            'hadith_grade' => 'صحيح',
            'narrator' => null,
            'guidance_note' => null,
            'notes' => null,
            'order' => 1,
            'active' => true,
            'content_version' => 1,
            'source_hash' => hash('sha256', $text),
        ];
    }
}
