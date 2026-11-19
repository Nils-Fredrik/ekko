import { trans } from 'laravel-vue-i18n';

// Marker lines and tooltip notes. Bucketing lives in data.js.

export function markerColor(palette, marker) {
    return marker?.kind === 'action' ? palette.markerAction : palette.markerModel;
}

export function markerText(marker) {
    const kind = trans(marker?.kind === 'action' ? 'app.charts.marker_action' : 'app.charts.marker_model');
    const detail = marker?.detail ? ` – ${marker.detail}` : '';

    return `${kind}: ${marker?.label ?? ''}${detail}`;
}

/** Tooltip notes for the markers in one bucket. */
export function markerNotes(palette, buckets, category) {
    return (buckets.get(category) ?? []).map((marker) => ({
        color: markerColor(palette, marker),
        text: markerText(marker),
    }));
}

/**
 * A series that carries only the marker lines, so the lines do not depend on any data series.
 * Clicking a line emits `marker` from EkkoChart with the marker object.
 */
export function markerSeries(palette, buckets) {
    const data = [];
    for (const [category, markers] of buckets) {
        markers.forEach((marker) => {
            data.push({
                xAxis: category,
                marker,
                lineStyle: {
                    color: markerColor(palette, marker),
                    type: marker.kind === 'action' ? 'solid' : 'dashed',
                    width: 1.5,
                },
                label: { show: false },
            });
        });
    }

    return {
        id: 'ekko-markers',
        name: 'markers',
        type: 'line',
        data: [],
        silent: false,
        tooltip: { show: false },
        markLine: {
            symbol: ['none', 'circle'],
            symbolSize: 7,
            animation: false,
            emphasis: { lineStyle: { width: 3 } },
            data,
        },
    };
}
