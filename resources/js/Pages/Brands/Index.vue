<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

defineProps({
    brands: { type: Array, required: true },
});
</script>

<template>
    <AppLayout :title="$t('app.nav.brands')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('app.nav.brands') }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <EmptyState
                    v-if="brands.length === 0"
                    :title="$t('app.empty.brands_title')"
                    :body="$t('app.empty.brands_body')"
                    :href="page.props.canOnboard ? route('onboarding.wizard') : ''"
                    :link="page.props.canOnboard ? $t('app.empty.wizard_link') : ''"
                />
                <div v-else class="overflow-hidden rounded-2xl border border-line bg-shell-panel text-shell-text">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th class="px-5 py-3 font-medium">{{ $t('app.dashboard.brands') }}</th>
                                <th class="px-5 py-3 font-medium">{{ $t('app.dashboard.status') }}</th>
                                <th class="px-5 py-3 font-medium">{{ $t('app.dashboard.markets') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="brand in brands" :key="brand.id" class="border-b border-line-soft last:border-0">
                                <td class="px-5 py-3">
                                    <Link
                                        :href="route('brands.show', brand.id)"
                                        class="font-medium text-shell-text no-underline"
                                    >
                                        {{ brand.name }}
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-shell-muted">{{ brand.status }}</td>
                                <td class="px-5 py-3 text-shell-muted">
                                    {{
                                        brand.markets.length
                                            ? brand.markets.map((market) => market.label).join(', ')
                                            : $t('app.dashboard.no_markets')
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
