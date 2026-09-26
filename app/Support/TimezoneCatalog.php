<?php

namespace App\Support;

class TimezoneCatalog
{
    /**
     * يحوّل ما يكتبه المستخدم إلى معرّف منطقة زمنية صالح.
     * يقبل معرّف IANA مباشرة، أو اسم دولة/مدينة بالعربية حتى مع أخطاء إملائية بسيطة.
     */
    /**
     * @return array{id: string, label: string}|null
     */
    public static function resolveWithLabel(string $input): ?array
    {
        $trimmed = trim($input);
        $id = self::resolve($trimmed);
        if ($id === null) {
            return null;
        }

        if (in_array($trimmed, timezone_identifiers_list(), true)) {
            return [
                'id' => $id,
                'label' => self::labelForTimezone($id) ?? 'منطقتك',
            ];
        }

        $label = self::labelForTimezone($id) ?? $trimmed;

        return ['id' => $id, 'label' => $label];
    }

    /**
     * Country buttons that need a second region step. Null when the name is a single zone.
     *
     * @return array{index: int, label: string, regions: array<int, array{label: string, id: string}>}|null
     */
    public static function multiZone(string $input): ?array
    {
        $normalized = self::normalize($input);
        if ($normalized === '') {
            return null;
        }

        foreach (config('adhkar.timezones', []) as $index => $country) {
            $regions = $country['regions'] ?? [];
            if ($regions === []) {
                continue;
            }

            $names = array_merge([$country['label'] ?? ''], $country['aliases'] ?? []);
            foreach ($names as $name) {
                if ($name !== '' && self::normalize($name) === $normalized) {
                    return [
                        'index' => (int) $index,
                        'label' => (string) $country['label'],
                        'regions' => $regions,
                    ];
                }
            }
        }

        return null;
    }

    public static function labelForTimezone(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        foreach (config('adhkar.timezones', []) as $country) {
            if (($country['id'] ?? null) === $timezone) {
                return $country['label'];
            }

            foreach ($country['regions'] ?? [] as $region) {
                if (($region['id'] ?? null) === $timezone) {
                    return $country['label'].' · '.$region['label'];
                }
            }
        }

        return null;
    }

    public static function resolve(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        if (in_array($trimmed, timezone_identifiers_list(), true)) {
            return $trimmed;
        }

        $aliases = config('adhkar.timezone_aliases', []);
        if (isset($aliases[$trimmed])) {
            return $aliases[$trimmed];
        }

        $normalized = self::normalize($trimmed);

        foreach (timezone_identifiers_list() as $id) {
            if (mb_strtolower($id) === $normalized) {
                return $id;
            }
            if (mb_strtolower(str_replace('_', ' ', $id)) === $normalized) {
                return $id;
            }
            $city = mb_strtolower((string) last(explode('/', $id)));
            if ($city === $normalized) {
                return $id;
            }
        }

        return self::fuzzyMatch($normalized, $aliases);
    }

    /**
     * مطابقة تقريبية تتسامح مع خطأ حرف أو حرفين، بعد تطبيع النص.
     *
     * @param  array<string, string>  $aliases
     */
    private static function fuzzyMatch(string $normalized, array $aliases): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $bestAlias = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($aliases as $alias => $timezone) {
            $candidate = self::normalize($alias);
            if ($candidate === '') {
                continue;
            }

            if ($candidate === $normalized) {
                return $timezone;
            }

            // التطبيع وحده يكفي غالبًا (همزات، "ال" التعريف)، فلا نجازف بمقارنة قصيرة جدًا.
            if (mb_strlen($normalized) < 4 || mb_strlen($candidate) < 4) {
                continue;
            }

            // levenshtein يعدّ البايتات، والحرف العربي يساوي بايتين، لذا نقيس بعدد الحروف.
            $distance = intdiv(levenshtein($normalized, $candidate), 2);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestAlias = $timezone;
            }
        }

        $limit = mb_strlen($normalized) >= 6 ? 2 : 1;

        return $bestDistance <= $limit ? $bestAlias : null;
    }

    /**
     * يزيل التشكيل والتطويل، ويوحّد أشكال الألف والهمزة، ويسقط "ال" التعريف، ويحوّل للحروف الصغيرة.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // الحركات، الشدة، التنوين، التطويل، والهمزة على السطر.
        $value = (string) preg_replace('/[ً-ْـٰء]/u', '', $value);

        $value = strtr($value, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);

        // "ال" التعريف في بداية الاسم أو بعد مسافة ("ابوظبي" تبقى كما هي).
        $value = (string) preg_replace('/(?<=^|\s)ال/u', '', $value);

        // توحيد المسافات المتكررة.
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
