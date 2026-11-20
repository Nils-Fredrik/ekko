@php
    $engines = $heatmap['engines'] ?? [];
    $rows = $heatmap['rows'] ?? [];
@endphp
@if ($engines !== [] && $rows !== [])
    <svg data-testid="report-prompt-heatmap" viewBox="0 0 580 {{ 28 + count($rows) * 18 }}" role="img" aria-label="{{ $label }}" style="width: 100%; max-height: {{ 28 + count($rows) * 18 }}px">
        @foreach ($engines as $index => $engine)
            <text x="{{ 150 + $index * 28 }}" y="12" font-size="8">{{ \Illuminate\Support\Str::limit((string) $engine, 4, '') }}</text>
        @endforeach
        @foreach ($rows as $rowIndex => $row)
            <text x="0" y="{{ 32 + $rowIndex * 18 }}" font-size="9">{{ \Illuminate\Support\Str::limit((string) $row['prompt'], 24) }}</text>
            @foreach ($row['cells'] as $cellIndex => $value)
                @php
                    $alpha = is_numeric($value) ? number_format(0.15 + 0.85 * max(0, min(1, (float) $value)), 2, '.', '') : '0.08';
                @endphp
                <rect x="{{ 150 + $cellIndex * 28 }}" y="{{ 20 + $rowIndex * 18 }}" width="22" height="14" fill="#b62b1b" fill-opacity="{{ $alpha }}" />
            @endforeach
        @endforeach
    </svg>
@endif
