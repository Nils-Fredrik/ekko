@php
    $periods = $series['periods'] ?? [];
    $lines = $series['lines'] ?? [];
    $colors = ['#1d4e89', '#4a7c59', '#a85e2e', '#6b4c7a', '#3f6f8f'];
    $start = isset($from) ? \Carbon\CarbonImmutable::parse($from)->startOfDay() : null;
    $end = isset($to) ? \Carbon\CarbonImmutable::parse($to)->startOfDay() : null;
    $span = $start !== null && $end !== null ? max(0, (int) $start->diffInDays($end)) : max(0, count($periods) - 1);
    $placed = [];
    foreach ($periods as $index => $period) {
        if ($start === null) {
            $placed[$index] = ['x' => 40 + 520 * $index / max(1, $span), 'day' => $index];
            continue;
        }
        $day = (int) $start->diffInDays(\Carbon\CarbonImmutable::parse($period)->startOfDay(), false);
        if ($day >= 0 && $day <= $span) {
            $placed[$index] = ['x' => 40 + 520 * $day / max(1, $span), 'day' => $day];
        }
    }
@endphp
@if ($span >= 1 && $lines !== [] && $placed !== [])
    <svg data-testid="report-competitor-lines" viewBox="0 0 580 180" role="img" aria-label="{{ __('reports.share_over_time') }}" style="width: 100%; max-height: 180px">
        <title>{{ __('reports.share_over_time') }}</title>
        <line x1="40" y1="10" x2="40" y2="150" stroke="#78716c" />
        <line x1="40" y1="150" x2="560" y2="150" stroke="#78716c" />
        <text x="0" y="14" font-size="10">100%</text>
        <text x="15" y="154" font-size="10">0%</text>
        @foreach ($lines as $index => $line)
            @php
                $segments = [];
                $segment = [];
                $previousDay = null;
                $dots = [];
                foreach ($line['points'] as $point => $value) {
                    if (! isset($placed[$point]) || ! is_numeric($value)) {
                        if ($segment !== []) {
                            $segments[] = $segment;
                        }
                        $segment = [];
                        $previousDay = null;
                        continue;
                    }
                    $x = round($placed[$point]['x'], 1);
                    $day = $placed[$point]['day'];
                    $y = round(10 + 140 * (1 - max(0, min(1, (float) $value))), 1);
                    if ($previousDay !== null && $day !== $previousDay + 1 && $segment !== []) {
                        $segments[] = $segment;
                        $segment = [];
                    }
                    $segment[] = $x.','.$y;
                    $previousDay = $day;
                    $sample = $line['ns'][$point] ?? null;
                    $dots[] = [
                        'x' => $x, 'y' => $y, 'low' => (bool) ($line['lows'][$point] ?? false),
                        'title' => $periods[$point].' · '.round((float) $value * 100, 1).'%'.(is_numeric($sample) ? ' · n '.$sample : ''),
                    ];
                }
                if ($segment !== []) {
                    $segments[] = $segment;
                }
                $color = ($line['own'] ?? false) ? '#b62b1b' : $colors[$index % count($colors)];
            @endphp
            @foreach ($segments as $segment)
                @if (count($segment) > 1)
                    <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ implode(' ', $segment) }}" />
                @endif
            @endforeach
            @foreach ($dots as $dot)
                <circle @if ($dot['low']) data-low-sample @endif cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="3" fill="{{ $dot['low'] ? '#a8a29e' : $color }}">
                    <title>{{ $dot['title'] }}</title>
                </circle>
            @endforeach
            <text x="{{ 40 + ($index * 90) }}" y="172" font-size="10" fill="{{ $color }}">{{ \Illuminate\Support\Str::limit((string) $line['name'], 14) }}</text>
        @endforeach
    </svg>
    @php
        $maxRank = 1;
        foreach ($lines as $line) {
            foreach ($line['ranks'] ?? [] as $rank) {
                if (is_numeric($rank)) {
                    $maxRank = max($maxRank, (int) $rank);
                }
            }
        }
    @endphp
    <svg data-testid="report-competitor-ranks" viewBox="0 0 580 180" role="img" aria-label="{{ __('reports.rank') }}" style="width: 100%; max-height: 180px">
        <title>{{ __('reports.rank') }}</title>
        <line x1="40" y1="10" x2="40" y2="150" stroke="#78716c" />
        <line x1="40" y1="150" x2="560" y2="150" stroke="#78716c" />
        <text x="0" y="14" font-size="10">1</text>
        <text x="0" y="154" font-size="10">{{ $maxRank }}</text>
        @foreach ($lines as $index => $line)
            @php
                $segments = [];
                $segment = [];
                $previousDay = null;
                foreach ($line['ranks'] ?? [] as $point => $rank) {
                    if (! isset($placed[$point]) || ! is_numeric($rank)) {
                        if ($segment !== []) {
                            $segments[] = $segment;
                        }
                        $segment = [];
                        $previousDay = null;
                        continue;
                    }
                    $x = round($placed[$point]['x'], 1);
                    $day = $placed[$point]['day'];
                    $y = round(10 + 140 * (((int) $rank - 1) / max(1, $maxRank - 1)), 1);
                    if ($previousDay !== null && $day !== $previousDay + 1 && $segment !== []) {
                        $segments[] = $segment;
                        $segment = [];
                    }
                    $segment[] = $x.','.$y;
                    $previousDay = $day;
                }
                if ($segment !== []) {
                    $segments[] = $segment;
                }
                $color = ($line['own'] ?? false) ? '#b62b1b' : $colors[$index % count($colors)];
            @endphp
            @foreach ($segments as $segment)
                @if (count($segment) > 1)
                    <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ implode(' ', $segment) }}" />
                @endif
            @endforeach
            <text x="{{ 40 + ($index * 90) }}" y="172" font-size="10" fill="{{ $color }}">{{ \Illuminate\Support\Str::limit((string) $line['name'], 14) }}</text>
        @endforeach
    </svg>
    <table data-testid="report-competitor-values">
        <thead>
            <tr>
                <th>{{ __('reports.changed_on') }}</th>
                <th>{{ __('reports.competitor') }}</th>
                <th>{{ __('reports.share_of_voice') }}</th>
                <th>{{ __('reports.sample_size') }}</th>
                <th>{{ __('reports.rank') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($periods as $point => $period)
                @if (! isset($placed[$point]))
                    @continue
                @endif
                @foreach ($lines as $line)
                    <tr @class(['low' => $line['lows'][$point] ?? false])>
                        <td>{{ $period }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td>{{ is_numeric($line['points'][$point] ?? null) ? round((float) $line['points'][$point] * 100, 1).'%' : __('reports.empty_value') }}</td>
                        <td>{{ $line['ns'][$point] ?? __('reports.empty_value') }}</td>
                        <td>{{ $line['ranks'][$point] ?? __('reports.empty_value') }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif
