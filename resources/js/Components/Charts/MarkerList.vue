<script setup>
import { markerText } from './support/markers.js';

defineProps({ markers: { type: Array, required: true } });
</script>

<template>
    <details v-if="markers.length" class="mt-2 text-sm text-shell-muted" data-testid="chart-markers">
        <summary class="cursor-pointer">{{ $t('app.charts.markers') }} ({{ markers.length }})</summary>
        <ul class="mt-2 space-y-1">
            <li v-for="marker in markers" :key="marker.key">
                <a v-if="marker.href" :href="marker.href" class="underline">{{ markerText(marker) }}</a>
                <span v-else>{{ markerText(marker) }}</span>
            </li>
        </ul>
    </details>
</template>
