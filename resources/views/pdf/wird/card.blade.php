@php
    // Tajawal has no glyph for ﷺ or the Quranic brackets and does not mirror guillemets, so those come from Amiri.
    $amiri = fn (string $text): string => preg_replace('/[\x{00AB}\x{00BB}\x{FD3E}\x{FD3F}\x{FDFA}\x{FDFD}]+/u', '<span class="ornament">$0</span>', e($text));
    // A blank line between surahs becomes a small gap instead of a whole empty line.
    $paragraphs = preg_split('/\n\s*\n/u', $t($item->arabic_text));

    $repsLabel = trim((string) $t($item->repetitions_label ?? ''));
    $repeated = ($item->repetitions_count ?? 0) > 1;
    $repsBadge = rtrim(preg_replace('/\s*\(.*\)\s*$/u', '', $repsLabel), '.');
    $repsNote = null;
    if (mb_strlen($repsBadge) > 16) {
        $repsNote = $repsLabel;
        $count = (int) $item->repetitions_count;
        $repsBadge = match (true) {
            (bool) preg_match('/(\d+) \+ \1 \+ \1/u', $repsLabel, $m) => "{$m[1]} · {$m[1]} · {$m[1]}",
            $count === 2 => 'مرتان',
            $count >= 3 && $count <= 10 => $count.' مرات',
            $count > 10 => $count.' مرة',
            default => null,
        };
    }
@endphp
<div class="entry{{ $compact > 0 ? ' compact-'.$compact : '' }}">
    <div class="head"><span class="num">&nbsp;{{ $number }}&nbsp;</span>&nbsp;&nbsp;<span class="title">{!! $amiri($t($item->title ?: 'الذكر '.$number)) !!}</span>@if (filled($repsBadge))&nbsp;&nbsp;&nbsp;<span class="{{ $repeated ? 'reps' : 'reps-once' }}">&nbsp;&nbsp;{{ $repsBadge }}&nbsp;&nbsp;</span>@endif</div>
    <div class="item">
        @if ($repsNote)
            <div class="reps-note">{{ $repsNote }}</div>
        @endif
        @foreach ($paragraphs as $paragraph)
            <div class="text{{ $loop->first ? '' : ' para' }}">{!! nl2br(e($paragraph)) !!}</div>
        @endforeach
        @if (filled($item->virtue))
            <div class="virtue"><b>الفضل:</b> {{ $t($item->virtue) }}</div>
        @endif
        @if (filled($item->source_name))
            <div class="source">{!! $amiri($t($item->source_name)) !!}</div>
        @endif
    </div>
</div>
