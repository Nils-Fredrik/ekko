@php
    $engines = $heatmap['engines'] ?? [];
    $rows = $heatmap['rows'] ?? [];
@endphp
@if ($engines !== [] && $rows !== [])
    <table data-testid="report-prompt-heatmap">
        <caption>{{ $label }}</caption>
        <thead>
            <tr>
                <th>{{ __('reports.prompt') }}</th>
                @foreach ($engines as $engine)
                    <th>{{ $engine }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <th scope="row">{{ $row['prompt'] }}</th>
                    @foreach ($row['cells'] as $cell)
                        @php
                            $strength = is_numeric($cell['value'] ?? null) ? max(0, min(1, (float) $cell['value'])) : 0;
                            $alpha = number_format(0.08 + 0.32 * $strength, 2, '.', '');
                            $rgb = ($cell['low'] ?? false) ? '168, 162, 158' : '182, 43, 27';
                        @endphp
                        <td @class(['low' => $cell['low'] ?? false]) style="background-color: rgba({{ $rgb }}, {{ $alpha }}); print-color-adjust: exact">{{ $cell['text'] ?? '–' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
