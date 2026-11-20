<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($shared ?? false) data-theme="light" @endif>
<head>
    <meta charset="utf-8">
    @if ($shared ?? false)
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">
    @endif
    <title>{{ __('reports.title', ['brand' => $brand]) }}</title>
    @if (($shared ?? false) && ! empty($trend))
        @vite('resources/js/shared-report.js')
    @endif
    @php
        $reportColor = \App\Reports\ReadableColor::against($profile['color'], '#ffffff');
    @endphp
    <style>
        html, body { margin: 0; background: #fff; color: #1c1917; font-family: sans-serif; font-size: 12px; }
        header { padding: 24px 32px; color: #fff; background: {{ $reportColor }}; }
        header h1 { margin: 0; font-size: 22px; }
        header p { margin: 6px 0 0; }
        header img { height: 36px; margin-bottom: 8px; }
        main { padding: 24px 32px; }
        h2 { margin: 22px 0 8px; font-size: 14px; color: {{ $reportColor }}; }
        @page { size: A4 portrait; margin: 14mm; }
        .report-section { break-after: page; }
        .report-section:last-child { break-after: auto; }
        .interactive { display: none; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e7e5e4; }
        th { font-size: 10px; color: #78716c; }
        .cards { width: 100%; }
        .cards td { width: 25%; vertical-align: top; border: 1px solid #e7e5e4; }
        .value { font-size: 16px; }
        .muted, .low { color: #78716c; }
        summary { box-sizing: border-box; min-height: 24px; min-width: 24px; padding: 2px 0; cursor: pointer; }
    </style>
</head>
<body>
    <header>
        @if ($profile['logo'])
            <img src="{{ $profile['logo'] }}" alt="{{ $profile['name'] }}">
        @elseif ($profile['name'] !== $brand)
            <p>{{ $profile['name'] }}</p>
        @endif
        <h1>{{ $brand }}</h1>
        <p>{{ __('reports.period', ['from' => $from, 'to' => $to]) }}</p>
    </header>
    <main>
        @if ($reconstructed ?? false)
            <p>{{ __('reports.reconstructed') }}</p>
        @endif
        <section class="report-section" id="report-cover">
            @if ($insight)
                <h2>{{ __('reports.insight') }}</h2>
                <p>{{ $insight }}</p>
            @endif
            <h2>{{ __('reports.summary', ['responses' => $responses]) }}</h2>
            <table class="cards">
                @foreach (array_chunk($headlines, 4) as $row)
                    <tr>
                        @foreach ($row as $headline)
                            <td>
                                <div class="muted">{{ $headline['label'] }}</div>
                                <div @class(['value', 'low' => $headline['low']])>{{ $headline['value'] }}</div>
                                <div class="muted">{{ $headline['change'] }}</div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </section>

        @if (! empty($trend))
            <section class="report-section" id="report-development">
                @include('reports.partials.pdf-trend')
                @if ($shared ?? false)
                    @php
                        $chartData = ['trend' => $trend, 'markers' => $markers ?? [], 'brand' => $brand, 'from' => $from, 'to' => $to, 'label' => __('reports.trend')];
                    @endphp
                    <div class="interactive" id="shared-report-chart"></div>
                    <script type="application/json" id="shared-report-chart-data">@json($chartData)</script>
                @endif
            </section>
        @endif

        <section class="report-section" id="report-engines">
            <h2>{{ __('reports.engines') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.engine'), __('reports.visibility'), __('reports.share_of_voice')], 'rows' => array_map(fn ($row) => [$row['engine'], $row['visibility'], $row['share_of_voice']], $engines)])
        </section>

        <section class="report-section" id="report-competitors">
            <h2>{{ __('reports.competitors') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.competitor'), __('reports.mentions'), __('reports.visibility'), __('reports.share_of_voice')], 'rows' => array_map(fn ($row) => [$row['name'], $row['mentions'], $row['visibility'], $row['share_of_voice']], $competitors)])
        </section>

        <section class="report-section" id="report-prompts">
            <h2>{{ __('reports.prompts') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.prompt'), __('reports.visibility'), __('reports.change')], 'rows' => array_map(fn ($row) => [$row['prompt'], $row['visibility'], $row['change']], $prompts)])
        </section>

        <section class="report-section" id="report-sources">
            <h2>{{ __('reports.sources') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.domain'), __('reports.citations')], 'rows' => array_map(fn ($row) => [$row['domain'], $row['citations']], $sources)])

            <h2>{{ __('reports.gaps') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.domain'), __('reports.gap')], 'rows' => array_map(fn ($row) => [$row['domain'], $row['gap_responses']], $gaps)])
        </section>

        <section class="report-section" id="report-actions">
        <h2>{{ $actions['title'] }}</h2>
        <h3>{{ $actions['open_title'] }}</h3>
        @forelse ($actions['open'] as $action)
            <p><strong>{{ $action['title'] }}</strong> · {{ $action['impact'] }}</p>
            @if ($action['evidence'] !== '')
                <p>{{ $action['evidence'] }}</p>
            @endif
        @empty
            <p class="muted">{{ $actions['empty_open'] }}</p>
        @endforelse
        <h3>{{ $actions['done_title'] }}</h3>
        @forelse ($actions['completed'] as $action)
            <p><strong>{{ $action['title'] }}</strong> · {{ $action['impact'] }}</p>
            @if ($action['evidence'] !== '')
                <p>{{ $action['evidence'] }}</p>
            @endif
            <p class="muted">{{ $actions['change_label'] }}: {{ $action['change'] }}</p>
        @empty
            <p class="muted">{{ $actions['empty_done'] }}</p>
        @endforelse
        </section>

        <section class="report-section" id="report-robots">
            <h2>{{ __('reports.bots') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.bot'), __('reports.hits')], 'rows' => array_map(fn ($row) => [$row['bot'], $row['hits']], $bots)])

            <h2>{{ __('reports.traffic') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.source'), __('reports.sessions'), __('reports.conversions')], 'rows' => array_map(fn ($row) => [$row['source'], $row['sessions'], $row['conversions']], $traffic)])
        </section>

        <section class="report-section" id="report-appendix">
            <h2>{{ __('reports.models') }}</h2>
            @include('reports.partials.table', ['columns' => [__('reports.engine'), __('reports.changed_on'), __('reports.from_model'), __('reports.to_model')], 'rows' => array_map(fn ($row) => [$row['engine'], $row['on'], $row['from'], $row['to']], $models)])
        </section>
    </main>
</body>
</html>
