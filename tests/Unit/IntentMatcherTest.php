<?php

namespace Tests\Unit;

use App\Support\IntentMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntentMatcherTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function phrases(): array
    {
        return [
            'morning greeting' => ['صباح الخير', 'morning'],
            'morning with hamza' => ['بدي أذكار الصباح', 'morning'],
            'evening dialect' => ['مسا الخير', 'evening'],
            'evening with taa marbuta' => ['أذكار المساءِ', 'evening'],
            'bedtime' => ['بدي نام', 'bedtime'],
            'good night' => ['تصبح على خير', 'bedtime'],
            'waking' => ['صحيت هلق', 'waking'],
            'pause wins over salam' => ['السلام عليكم، وقف التذكير', 'pause'],
            'stop sending' => ['لا تبعتلي', 'pause'],
            'resume' => ['شغّل التذكير', 'resume'],
            'time' => ['بدي غيّر الوقت', 'time'],
            'help' => ['مساعدة', 'help'],
            'lost' => ['ما فهمت شي', 'help'],
            'thanks stretched' => ['شكراااا', 'thanks'],
            'jazak' => ['جزاك الله خير', 'thanks'],
            'salam' => ['السلام عليكم', 'salam'],
            'hello' => ['مرحبا', 'greeting'],
            'nothing' => ['بطاطا', null],
            'partial word is not enough' => ['موقف السيارات', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('phrases')]
    public function test_it_understands_everyday_phrases(string $text, ?string $intent): void
    {
        $this->assertSame($intent, IntentMatcher::match($text));
    }

    public function test_normalize_folds_spellings(): void
    {
        $this->assertSame('اذكار المساء', IntentMatcher::normalize('أذكارُ المساءِ!'));
        $this->assertSame('مكتبه', IntentMatcher::normalize('مكتبة'));
        $this->assertSame('علي', IntentMatcher::normalize('على'));
    }
}
