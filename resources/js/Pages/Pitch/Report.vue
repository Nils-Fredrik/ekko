<script setup>
import ReportSection from '@/Components/Actions/ReportSection.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: String,
    brandId: Number,
    from: String,
    to: String,
    visibility: Object,
    competitors: Array,
    sources: Array,
    actions: Object,
});
function percent(value) {
    return value === null || value === undefined
        ? '–'
        : new Intl.NumberFormat(document.documentElement.lang || 'nb', {
              style: 'percent',
              maximumFractionDigits: 1,
          }).format(value);
}
</script>

<template>
    <AppLayout :title="brand">
        <template #header>
            <h2 class="text-xl font-medium">{{ brand }} · {{ $t('pitch.report') }}</h2>
        </template>
        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8">
            <a :href="route('pitches.report.pdf', brandId)" class="text-sm underline">{{ $t('pitch.download_pdf') }}</a>
            <p>{{ $t('app.workspace.period_range', { start: from, end: to }) }}</p>
            <section>
                <h3>{{ $t('pitch.visibility') }}</h3>
                <p :class="{ 'opacity-50': visibility.low_sample }">
                    {{ $t('pitch.metric', { name: brand, value: percent(visibility.value), n: visibility.n }) }}
                </p>
                <p v-for="row in competitors" :key="row.name" :class="{ 'opacity-50': row.visibility.low_sample }">
                    {{
                        $t('pitch.metric', {
                            name: row.name,
                            value: percent(row.visibility.value),
                            n: row.visibility.n,
                        })
                    }}
                </p>
            </section>
            <section>
                <h3>{{ $t('pitch.sources') }}</h3>
                <p v-for="row in sources" :key="row.domain">{{ row.domain }}: {{ row.citations }}</p>
            </section>
            <ReportSection :actions="actions" />
        </div>
    </AppLayout>
</template>
