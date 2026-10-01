<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    body { font-family: amiri; direction: rtl; color: #2b2b2b; font-size: 12pt; }

    .page-header { text-align: center; font-size: 9.5pt; color: #8a7148; border-bottom: 0.3mm solid #d8c7a3; padding-bottom: 1.5mm; }
    .page-footer { text-align: center; font-size: 9.5pt; color: #8a7148; }

    .cover { border: 0.6mm solid #b08d57; padding: 1.2mm; margin-bottom: 5mm; }
    .cover-inner { border: 0.25mm solid #d8c7a3; background-color: #fbf7ee; text-align: center; padding: 2.5mm 4mm 3mm 4mm; }
    .ornament { color: #b08d57; font-size: 13pt; }
    .cover h1 { font-size: 23pt; font-weight: bold; color: #0b4f3c; margin: 0; line-height: 1.4; }
    .subtitle { font-size: 12pt; color: #6b5a3a; margin-top: 1mm; }

    .item { border: 0.3mm solid #e6dcc6; background-color: #fffdf8; padding: 3mm 4mm 3.5mm 4mm; margin-bottom: 5mm; page-break-inside: avoid; }
    .head { width: 100%; border-collapse: collapse; }
    .num { width: 8mm; height: 8mm; background-color: #0b4f3c; color: #ffffff; font-weight: bold; font-size: 11pt; text-align: center; vertical-align: middle; }
    .title { font-weight: bold; font-size: 14pt; color: #0b4f3c; padding-right: 3mm; vertical-align: middle; }
    .reps { margin-top: 1.5mm; font-size: 10pt; color: #8a5a00; }
    .reps span { background-color: #f6ecd6; padding: 0.6mm 2.5mm; }
    .text { font-size: 16.5pt; line-height: 2; text-align: justify; color: #1d1d1d; margin: 2.5mm 0 1mm 0; }
    .virtue { background-color: #eef5f1; border-right: 1mm solid #2f7d5b; padding: 2mm 3mm; font-size: 11pt; line-height: 1.7; color: #22473a; margin-top: 2mm; }
    .virtue b { color: #0b4f3c; }
    .source { font-size: 9.5pt; color: #7a7a7a; margin-top: 2mm; }

    .closing { text-align: center; margin-top: 6mm; }
    .closing .ornament { font-size: 18pt; }
    .closing-text { font-size: 13.5pt; color: #0b4f3c; font-weight: bold; line-height: 1.8; }
</style>
</head>
<body>
    <htmlpageheader name="main">
        <div class="page-header">{{ $heading }} · {{ $subtitle }}</div>
    </htmlpageheader>
    <htmlpagefooter name="main">
        <div class="page-footer">— {PAGENO} —</div>
    </htmlpagefooter>
    <sethtmlpageheader name="main" value="on" show-this-page="0" />
    <sethtmlpagefooter name="main" value="on" />

    <div class="cover">
        <div class="cover-inner">
            <div class="ornament">۞</div>
            <h1>{{ $heading }}</h1>
            <div class="subtitle">{{ $subtitle }}</div>
        </div>
    </div>

    @foreach ($items as $index => $item)
        <div class="item">
            <table class="head">
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td class="title">{{ $t($item->title ?: 'الذكر '.($index + 1)) }}</td>
                </tr>
            </table>
            @if (filled($item->repetitions_label))
                <div class="reps"><span>التكرار: {{ $t($item->repetitions_label) }}</span></div>
            @endif
            <div class="text">{!! nl2br(e($t($item->arabic_text))) !!}</div>
            @if (filled($item->virtue))
                <div class="virtue"><b>الفضل:</b> {{ $t($item->virtue) }}</div>
            @endif
            @if (filled($item->source_name))
                <div class="source">المصدر: {{ $t($item->source_name) }}@if (filled($item->hadith_grade)) · {{ $t($item->hadith_grade) }}@endif</div>
            @endif
        </div>
    @endforeach

    <div class="closing">
        <div class="ornament">۞</div>
        <div class="closing-text">{!! nl2br(e($t($footer))) !!}</div>
    </div>
</body>
</html>
