@php
    $scale = $scale ?? 'rate';
    $max = 1.0;
    if ($scale === 'count') {
        $max = 1.0;
        foreach ($items as $item) {
            $max = max($max, (float) ($item['value'] ?? 0));
        }
    }
    $height = 8 + count($items) * 22;
@endphp
@if ($items !== [])
    <svg data-testid="report-bars-{{ $name }}" viewBox="0 0 580 {{ $height }}" role="img" aria-label="{{ $label }}" style="width: 100%; max-height: {{ $height }}px">
        @foreach ($items as $index => $item)
            @php
                $value = is_numeric($item['value'] ?? null) ? (float) $item['value'] : 0.0;
                $width = $max > 0 ? 360 * max(0, $value) / $max : 0;
                $y = 8 + $index * 22;
                $low = (bool) ($item['low'] ?? false);
            @endphp
            <text x="0" y="{{ $y + 12 }}" font-size="10">{{ \Illuminate\Support\Str::limit((string) $item['label'], 28) }}</text>
            <rect x="160" y="{{ $y }}" width="{{ round($width, 1) }}" height="14" fill="{{ $low ? '#a8a29e' : '#b62b1b' }}" />
        @endforeach
    </svg>
@endif
