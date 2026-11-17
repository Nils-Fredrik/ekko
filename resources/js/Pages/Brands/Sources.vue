<script setup>
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    markets: { type: Array, required: true },
    engines: { type: Array, required: true },
    share: { type: Array, required: true },
    gap: { type: Array, required: true },
});

function rate(row, key) {
    return { value: row[key], n: row.n, low_sample: row.low_sample };
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <BrandNav :brand-id="brand.id" />
                <PeriodFilters
                    :brand-id="brand.id"
                    route-name="brands.sources"
                    :days="period.days"
                    :markets="markets"
                    :market="filters.market"
                    :engines="engines"
                    :engine="filters.engine || ''"
                />

                <section class="overflow-x-auto rounded-2xl border border-line bg-white text-ink">
                    <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.library.share') }}</h3>
                    <p v-if="share.length === 0" class="px-4 py-4 text-sm text-ink-soft">
                        {{ $t('app.library.no_sources') }}
                    </p>
                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-ink-soft">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.domain') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.citations') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.share') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in share" :key="row.domain" class="border-b border-line-soft last:border-0">
                                <td class="px-4 py-3">{{ row.domain }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.citations }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <MetricValue :metric="rate(row, 'share')" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-x-auto rounded-2xl border border-line bg-white text-ink">
                    <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.library.gap') }}</h3>
                    <p v-if="gap.length === 0" class="px-4 py-4 text-sm text-ink-soft">
                        {{ $t('app.library.no_gap') }}
                    </p>
                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-ink-soft">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.domain') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.gap') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in gap" :key="row.domain" class="border-b border-line-soft last:border-0">
                                <td class="px-4 py-3">{{ row.domain }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <MetricValue :metric="rate(row, 'gap_rate')" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
