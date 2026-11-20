<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand }} · {{ __('pitch.report') }}</title>
    <style>
        body { margin: 0; color: #1c1917; font: 12px sans-serif; }
        header { padding: 24px 32px; color: white; background: #e84a38; }
        header h1 { margin: 0; font-size: 22px; }
        main { padding: 24px 32px; }
        h2 { margin: 22px 0 8px; font-size: 14px; color: #e84a38; }
        .low { color: #a8a29e; }
        .action { margin-bottom: 12px; }
    </style>
</head>
<body>
    <header><h1>{{ $brand }}</h1><p>{{ __('pitch.report') }} · {{ $from }} – {{ $to }}</p></header>
    <main>
        @if ($reduced_quality)<p>{{ __('pitch.reduced_quality', ['n' => $reduced_quality]) }}</p>@endif
        <h2>{{ __('pitch.visibility') }}</h2>
        @foreach ([['name' => $brand, 'visibility' => $visibility], ...$competitors] as $row)
            @php($metric = $row['visibility'])
            <p @class(['low' => $metric['low_sample']])>{{ __('pitch.metric', ['name' => $row['name'], 'value' => $metric['value'] === null ? '–' : number_format($metric['value'] * 100, 1, app()->getLocale() === 'en' ? '.' : ',', ' ').' %', 'n' => $metric['n']]) }}</p>
        @endforeach
        <h2>{{ __('pitch.sources') }}</h2>
        @foreach ($sources as $source)<p>{{ $source['domain'] }}: {{ $source['citations'] }}</p>@endforeach
        <h2>{{ $actions['title'] }}</h2>
        @forelse ($actions['open'] as $action)
            <div class="action"><strong>{{ $action['title'] }}</strong><p>{{ $action['impact'] }}</p><p>{{ $action['evidence'] }}</p></div>
        @empty
            <p>{{ $actions['empty_open'] }}</p>
        @endforelse
    </main>
</body>
</html>
