@php
    // Use calendar-day positions and break the line for missing observations.
    $start = \Carbon\CarbonImmutable::parse($from)->startOfDay();
    $end = \Carbon\CarbonImmutable::parse($to)->startOfDay();
    $days = (int) $start->diffInDays($end);
    $points = [];
    if ($days >= 0 && $days <= 366) {
        foreach ($trend as $item) {
            $value = data_get($item, 'visibility.value');
            if (! is_numeric($value) || $value < 0 || $value > 1) {
                continue;
            }
            $date = \Carbon\CarbonImmutable::parse($item['period'])->startOfDay();
            $day = (int) $start->diffInDays($date, false);
            if ($day < 0 || $day > $days) {
                continue;
            }
            $n = data_get($item, 'visibility.n');
            $n = is_numeric($n) && $n >= 0 ? (int) $n : null;
            $points[$day] = [
                'x' => 40 + 520 * $day / max(1, $days), 'y' => 10 + 160 * (1 - (float) $value),
                'date' => $date->toDateString(), 'value' => (float) $value, 'n' => $n,
                'low' => (bool) data_get($item, 'visibility.low_sample', false) || ($n !== null && $n < 10),
            ];
        }
        ksort($points);
    }
@endphp
@if ($points !== [])
    <section data-testid="pdf-report-trend">
        <h2>{{ __('reports.trend') }}</h2>
        <svg viewBox="0 0 580 200" role="img" aria-label="{{ __('reports.trend') }}" style="width: 100%; max-height: 200px">
            <line x1="40" y1="10" x2="40" y2="170" stroke="#78716c" />
            <line x1="40" y1="170" x2="560" y2="170" stroke="#78716c" />
            <text x="0" y="14" font-size="10">100%</text>
            <text x="15" y="174" font-size="10">0%</text>
            <text x="40" y="190" font-size="10">{{ $from }}</text>
            <text x="560" y="190" font-size="10" text-anchor="end">{{ $to }}</text>
            @php $previous = null; @endphp
            @foreach ($points as $day => $point)
                @if ($previous !== null && $day === $previous['day'] + 1)
                    <line x1="{{ $previous['x'] }}" y1="{{ $previous['y'] }}" x2="{{ $point['x'] }}" y2="{{ $point['y'] }}" stroke="{{ $previous['low'] || $point['low'] ? '#a8a29e' : '#b62b1b' }}" stroke-width="2" />
                @endif
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" fill="{{ $point['low'] ? '#a8a29e' : '#b62b1b' }}"><title>{{ $point['date'] }} · {{ round($point['value'] * 100, 1) }}%</title></circle>
                @php $previous = ['day' => $day, ...$point]; @endphp
            @endforeach
        </svg>
        <table data-testid="pdf-trend-values">
            <thead><tr><th>{{ __('reports.changed_on') }}</th><th>{{ __('reports.visibility') }}</th><th>{{ __('reports.sample_size') }}</th></tr></thead>
            <tbody>
                @foreach ($points as $point)
                    <tr @class(['low' => $point['low']])>
                        <td>{{ $point['date'] }}</td>
                        <td>{{ round($point['value'] * 100, 1) }}%</td>
                        <td>{{ $point['n'] ?? __('reports.empty_value') }}@if ($point['low']) · {{ __('reports.low_sample') }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
