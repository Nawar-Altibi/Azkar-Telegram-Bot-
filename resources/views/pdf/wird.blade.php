<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    @page { background-color: #f7f4ec; }
    body { font-family: amiri; direction: rtl; color: #23302c; font-size: 12pt; }

    .page-header { font-family: tajawal; text-align: center; font-size: 8pt; color: #a8925f; }
    .page-footer { font-family: tajawal; text-align: center; font-size: 8.5pt; color: #a8925f; }

    .cover { background-color: #0e5a48; background-gradient: linear #0b4a3b #157059 0 0 1 1; border-radius: 4mm; padding: 6mm 6mm 5mm 6mm; text-align: center; margin-bottom: 5mm; }
    .ornament { font-family: amiri; }
    .cover .ornament { color: #d9bf86; font-size: 13pt; }
    .cover h1 { font-family: tajawal; font-weight: bold; font-size: 22pt; color: #ffffff; margin: 1mm 0 0 0; line-height: 1.3; }
    .cover .subtitle { font-family: tajawal; font-size: 10pt; color: #e8d6ad; margin-top: 1.5mm; }

    .entry { margin-bottom: 4mm; }
    .item { background-color: #ffffff; border: 0.25mm solid #e9e1cf; border-radius: 3mm; padding: 2mm 4mm 3mm 4mm; }
    .keep { page-break-inside: avoid; }
    /* Above the card, with a tall line and page-break-after, so a heading moves with its text and no empty frame is left. */
    .head { font-family: tajawal; line-height: 2.3; padding-right: 1mm; page-break-after: avoid; }
    .num { font-weight: bold; font-size: 9.5pt; color: #ffffff; background-color: #0e5a48; }
    .title { font-weight: bold; font-size: 11.5pt; color: #0e5a48; }
    .reps { font-size: 8.5pt; color: #8a6a1f; background-color: #f6eedb; }
    .text { font-size: 16pt; line-height: 1.9; text-align: justify; color: #1b2724; margin: 0.5mm 0 0 0; }
    .virtue { background-color: #f1f7f4; border-right: 0.8mm solid #3d8c70; padding: 1.8mm 3mm; font-size: 11pt; line-height: 1.65; color: #2b4a3f; margin-top: 2mm; }
    .virtue b { color: #0e5a48; }
    .source { font-family: tajawal; font-size: 8pt; color: #8d8a80; margin-top: 1.8mm; }

    .closing { page-break-inside: avoid; text-align: center; margin-top: 3mm; padding: 4mm 6mm; border-top: 0.25mm solid #e2d5b5; }
    .closing .ornament { color: #b8975a; font-size: 14pt; }
    .closing-title { font-family: tajawal; font-weight: bold; font-size: 13pt; color: #0e5a48; margin-top: 1mm; }
    .closing-blessing { font-size: 13pt; color: #6d5a33; margin-top: 1mm; }
</style>
</head>
<body>
    <htmlpageheader name="main">
        <div class="page-header">{{ $heading }} · {{ $subtitle }}</div>
    </htmlpageheader>
    <htmlpagefooter name="main">
        <div class="page-footer">{PAGENO}</div>
    </htmlpagefooter>
    <sethtmlpageheader name="main" value="on" show-this-page="0" />
    <sethtmlpagefooter name="main" value="on" />

    <div class="cover">
        <div class="ornament">۞</div>
        <h1>{{ $heading }}</h1>
        <div class="subtitle">{{ $subtitle }}</div>
    </div>

    @php
        [$closingTitle, $closingBlessing] = array_pad(preg_split('/\n\s*\n/u', $t($footer), 2), 2, '');
        // Tajawal has no glyph for ﷺ or the Quranic brackets and does not mirror guillemets, so those come from Amiri.
        $amiriGlyphs = fn (string $text): string => preg_replace('/[\x{00AB}\x{00BB}\x{FD3E}\x{FD3F}\x{FDFA}\x{FDFD}]+/u', '<span class="ornament">$0</span>', e($text));
    @endphp
    @foreach ($items as $index => $item)
        @php
            $text = $t($item->arabic_text);
            // Short cards stay whole; a long one may continue on the next page instead of leaving a gap.
            $keep = mb_strlen($text) + mb_strlen((string) $item->virtue) < $splitAbove;
            // The closing travels with a short last card, so it never sits alone on the final page.
            $last = $loop->last && $keep;
        @endphp
        @if ($last)<div class="keep">@endif
        <div class="entry{{ $keep && ! $last ? ' keep' : '' }}">
            <div class="head"><span class="num">&nbsp;{{ $index + 1 }}&nbsp;</span>&nbsp;&nbsp;<span class="title">{!! $amiriGlyphs($t($item->title ?: 'الذكر '.($index + 1))) !!}</span>@if (filled($item->repetitions_label))&nbsp;&nbsp;&nbsp;<span class="reps">&nbsp;{{ $t($item->repetitions_label) }}&nbsp;</span>@endif</div>
            <div class="item">
                <div class="text">{!! nl2br(e($text)) !!}</div>
                @if (filled($item->virtue))
                    <div class="virtue"><b>الفضل:</b> {{ $t($item->virtue) }}</div>
                @endif
                @if (filled($item->source_name))
                    <div class="source">{!! $amiriGlyphs($t($item->source_name)) !!}@if (filled($item->hadith_grade)) · {!! $amiriGlyphs($t($item->hadith_grade)) !!}@endif</div>
                @endif
            </div>
        </div>
        @if ($loop->last)
            <div class="closing">
                <div class="ornament">۞</div>
                <div class="closing-title">{{ $closingTitle }}</div>
                @if (filled($closingBlessing))
                    <div class="closing-blessing">{{ $closingBlessing }}</div>
                @endif
            </div>
        @endif
        @if ($last)</div>@endif
    @endforeach
</body>
</html>
