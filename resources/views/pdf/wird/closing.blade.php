@php
    [$title, $blessing] = array_pad(preg_split('/\n\s*\n/u', $footer, 2), 2, '');
@endphp
<div class="closing">
    <div class="ornament">۞</div>
    <div class="closing-title">{{ $title }}</div>
    @if (filled($blessing))
        <div class="closing-blessing">{{ $blessing }}</div>
    @endif
</div>
