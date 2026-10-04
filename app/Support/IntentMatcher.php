<?php

namespace App\Support;

/**
 * Maps everyday Arabic phrases to a bot action by keyword lookup only.
 */
final class IntentMatcher
{
    /**
     * Checked in this order, so a request ("وقف التذكير") wins over a greeting in the same message.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYWORDS = [
        'pause' => ['وقف', 'اوقف', 'وقفه', 'وقفها', 'ايقاف', 'لا تذكرني', 'لا تبعتلي', 'بطل تذكرني', 'الغي التذكير'],
        'resume' => ['شغل', 'شغله', 'شغلها', 'تشغيل', 'فعل', 'فعله', 'تفعيل', 'رجع التذكير', 'ارجع التذكير', 'رجعه', 'رجعها'],
        'bedtime' => ['نوم', 'النوم', 'بدي نام', 'رح نام', 'قبل ما نام', 'تصبح علي خير', 'تصبحون علي خير'],
        'waking' => ['صحيت', 'فقت', 'استيقاظ', 'الاستيقاظ', 'قمت من النوم', 'صحيان'],
        'morning' => ['صباح', 'الصباح', 'صبح', 'الصبح', 'بالصبح', 'صباحيه'],
        'evening' => ['مسا', 'المسا', 'مساء', 'المساء', 'بالمسا', 'مسائيه'],
        'time' => ['وقت', 'الوقت', 'الاوقات', 'موعد', 'الموعد', 'الساعه', 'غير الوقت'],
        'settings' => ['اعدادات', 'الاعدادات', 'اعداد'],
        'help' => ['مساعده', 'المساعده', 'ساعدني', 'شرح', 'اشرح', 'كيف', 'شلون', 'ما فهمت', 'مش فاهم', 'مو فاهم'],
        'thanks' => ['شكرا', 'شكرن', 'يسلمو', 'يسلموا', 'تسلم', 'جزاك الله', 'جزاكم الله', 'الله يجزيك', 'بارك الله فيك'],
        'salam' => ['سلام', 'السلام عليكم', 'سلام عليكم'],
        'greeting' => ['مرحبا', 'مرحبه', 'هلا', 'اهلا', 'هاي'],
    ];

    public static function match(string $text): ?string
    {
        $normalized = ' '.self::normalize($text).' ';
        if (trim($normalized) === '') {
            return null;
        }

        foreach (self::KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, ' '.self::normalize($keyword).' ')) {
                    return $intent;
                }
            }
        }

        return null;
    }

    /**
     * Folds the spellings people actually type into one form: alef and hamza shapes, taa marbuta,
     * alef maqsura, harakat, tatweel, punctuation and repeated letters ("شكراااا").
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/(\p{L})\1{2,}/u', '$1', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
