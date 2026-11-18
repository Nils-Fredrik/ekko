@if ($rows === [])
    <p class="muted">{{ __('reports.empty') }}</p>
@else
    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        @if (is_array($cell))
                            <td @class(['low' => $cell['low'] ?? false])>{{ $cell['text'] }}</td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
