<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

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
                <p v-if="brands.length === 0" class="text-sm text-ink-soft">{{ $t('app.dashboard.no_brands') }}</p>
                <div v-else class="overflow-hidden rounded-2xl border border-line bg-white text-ink">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-ink-soft">
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
                                        class="font-medium text-ink no-underline"
                                    >
                                        {{ brand.name }}
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-ink-soft">{{ brand.status }}</td>
                                <td class="px-5 py-3 text-ink-soft">
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
