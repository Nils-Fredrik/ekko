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
                        <td @class(['low' => $cell['low'] ?? false])>{{ $cell['text'] ?? '–' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
