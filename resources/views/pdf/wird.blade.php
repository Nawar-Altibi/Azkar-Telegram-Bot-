<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: xbriyaz; direction: rtl; text-align: right; color: #1f2933; font-size: 12pt; }
    h1 { text-align: center; font-size: 18pt; color: #0f5132; margin: 0 0 4mm 0; }
    .item { margin-bottom: 5mm; padding-bottom: 3mm; border-bottom: 0.3mm solid #d9e2ec; }
    .count { color: #627d98; font-size: 9pt; }
    .title { font-weight: bold; font-size: 13pt; color: #0f5132; }
    .reps { color: #486581; font-size: 10pt; }
    .text { font-size: 15pt; line-height: 1.9; margin: 2mm 0; }
    .virtue { background-color: #f0f7f4; border-right: 1mm solid #2f855a; padding: 2mm 3mm; font-size: 10.5pt; }
    .source { color: #627d98; font-size: 9.5pt; font-style: italic; }
    .footer { text-align: center; color: #0f5132; font-size: 12pt; margin-top: 4mm; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @foreach ($items as $index => $item)
        <div class="item">
            <div class="count">الذكر {{ $index + 1 }} من {{ $items->count() }}</div>
            <div class="title">{{ $item->title ?: 'الذكر '.($index + 1) }}</div>
            @if (filled($item->repetitions_label))
                <div class="reps">التكرار: {{ $item->repetitions_label }}</div>
            @endif
            <div class="text">{!! nl2br(e($item->arabic_text)) !!}</div>
            @if (filled($item->virtue))
                <div class="virtue">الفضل: {{ $item->virtue }}</div>
            @endif
            @if (filled($item->source_name))
                <div class="source">المصدر: {{ $item->source_name }}@if (filled($item->hadith_grade)) · {{ $item->hadith_grade }}@endif</div>
            @endif
        </div>
    @endforeach

    <div class="footer">{!! nl2br(e($footer)) !!}</div>
</body>
</html>
