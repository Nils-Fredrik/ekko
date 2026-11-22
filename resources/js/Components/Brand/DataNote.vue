<script setup>
import { InformationCircleIcon } from '@heroicons/vue/24/outline';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDate } from '@/format';

const page = usePage();
const period = computed(() => page.props.period ?? {});
const show = computed(
    () =>
        page.props.analysisFilters?.automaticPeriod &&
        period.value.measured_from &&
        period.value.measured_from > period.value.from,
);
</script>

<template>
    <p
        v-if="show"
        class="flex items-start gap-3 rounded-xl border border-line-soft bg-shell-surface p-4 text-sm text-shell-text"
        data-testid="data-note"
    >
        <InformationCircleIcon class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <span>
            {{ $t('app.dashboard.data_note', { date: formatDate(period.measured_from), days: period.days }) }}
        </span>
    </p>
</template>
