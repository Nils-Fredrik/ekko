<script setup>
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    bots: { type: Array, required: true },
    traffic: { type: Array, required: true },
});
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <BrandNav :brand-id="brand.id" />
                <PeriodFilters :brand-id="brand.id" route-name="brands.crawlers" />

                <section
                    tabindex="0"
                    aria-labelledby="crawler-bots-heading"
                    class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    <h3 id="crawler-bots-heading" class="px-4 pt-4 text-sm font-medium">
                        {{ $t('app.library.bots') }}
                    </h3>
                    <EmptyState
                        v-if="bots.length === 0"
                        class="m-4"
                        :title="$t('app.empty.bots_title')"
                        :body="$t('app.empty.bots_body')"
                    />
                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.bot') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.hits') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in bots" :key="row.bot" class="border-b border-line-soft last:border-0">
                                <td class="px-4 py-3">{{ row.bot }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.hits }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section
                    tabindex="0"
                    aria-labelledby="crawler-traffic-heading"
                    class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    <h3 id="crawler-traffic-heading" class="px-4 pt-4 text-sm font-medium">
                        {{ $t('app.library.traffic') }}
                    </h3>
                    <EmptyState
                        v-if="traffic.length === 0"
                        class="m-4"
                        :title="$t('app.empty.traffic_title')"
                        :body="$t('app.empty.traffic_body')"
                    />
                    <table v-else class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.source') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.sessions') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.engaged') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.conversions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in traffic"
                                :key="row.source"
                                class="border-b border-line-soft last:border-0"
                            >
                                <td class="px-4 py-3">{{ row.source }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.sessions }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.engaged_sessions }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.conversions }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
