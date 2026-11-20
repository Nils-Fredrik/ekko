@php
    $periods = $series['periods'] ?? [];
    $lines = $series['lines'] ?? [];
    $count = count($periods);
    $colors = ['#1d4e89', '#4a7c59', '#a85e2e', '#6b4c7a', '#3f6f8f'];
@endphp
@if ($count > 1 && $lines !== [])
    <svg data-testid="report-competitor-lines" viewBox="0 0 580 180" role="img" aria-label="{{ $label }}" style="width: 100%; max-height: 180px">
        <line x1="40" y1="10" x2="40" y2="150" stroke="#78716c" />
        <line x1="40" y1="150" x2="560" y2="150" stroke="#78716c" />
        @foreach ($lines as $index => $line)
            @php
                $commands = [];
                foreach ($line['points'] as $point => $value) {
                    if (! is_numeric($value)) {
                        continue;
                    }
                    $x = 40 + 520 * $point / max(1, $count - 1);
                    $y = 10 + 140 * (1 - max(0, min(1, (float) $value)));
                    $commands[] = round($x, 1).','.round($y, 1);
                }
                $color = ($line['own'] ?? false) ? '#b62b1b' : $colors[$index % count($colors)];
            @endphp
            @if ($commands !== [])
                <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ implode(' ', $commands) }}" />
            @endif
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
    <svg data-testid="report-competitor-ranks" viewBox="0 0 580 180" role="img" aria-label="{{ $label }}" style="width: 100%; max-height: 180px">
        <line x1="40" y1="10" x2="40" y2="150" stroke="#78716c" />
        <line x1="40" y1="150" x2="560" y2="150" stroke="#78716c" />
        <text x="0" y="14" font-size="10">1</text>
        <text x="0" y="154" font-size="10">{{ $maxRank }}</text>
        @foreach ($lines as $index => $line)
            @php
                $commands = [];
                foreach ($line['ranks'] ?? [] as $point => $rank) {
                    if (! is_numeric($rank)) {
                        continue;
                    }
                    $x = 40 + 520 * $point / max(1, $count - 1);
                    $y = 10 + 140 * (((int) $rank - 1) / max(1, $maxRank - 1));
                    $commands[] = round($x, 1).','.round($y, 1);
                }
                $color = ($line['own'] ?? false) ? '#b62b1b' : $colors[$index % count($colors)];
            @endphp
            @if ($commands !== [])
                <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ implode(' ', $commands) }}" />
            @endif
        @endforeach
    </svg>
@endif
