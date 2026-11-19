<script setup>
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    markets: { type: Array, required: true },
    competitors: { type: Array, required: true },
    suggestions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const pending = ref([]);

function add(suggestion) {
    if (pending.value.includes(suggestion.name)) {
        return;
    }

    pending.value = [...pending.value, suggestion.name];
    router.post(
        route('brands.competitors.store', props.brand.id),
        { name: suggestion.name },
        {
            preserveScroll: true,
            onFinish: () => {
                pending.value = pending.value.filter((name) => name !== suggestion.name);
            },
        },
    );
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
                    route-name="brands.competitors.index"
                    :days="period.days"
                    :markets="markets"
                    :market="filters.market"
                />

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <h3 class="text-sm font-medium">{{ $t('app.library.suggestions') }}</h3>
                    <EmptyState
                        v-if="suggestions.length === 0"
                        class="mt-3"
                        :title="$t('app.empty.suggestions_title')"
                        :body="$t('app.empty.suggestions_body')"
                    />
                    <ul v-else class="mt-3 flex flex-col gap-3">
                        <li
                            v-for="suggestion in suggestions"
                            :key="suggestion.name"
                            class="flex items-center justify-between gap-3"
                        >
                            <span>
                                {{ suggestion.name }}
                                <span class="text-xs text-shell-muted">
                                    {{ $t('app.library.suggestion_count', { count: suggestion.responses }) }}
                                </span>
                            </span>
                            <button
                                v-if="canManage"
                                type="button"
                                class="rounded-full bg-primary px-3 py-1 text-xs font-medium text-white hover:bg-primary-deep disabled:opacity-40"
                                :disabled="pending.includes(suggestion.name)"
                                @click="add(suggestion)"
                            >
                                {{ $t('app.library.add_competitor') }}
                            </button>
                        </li>
                    </ul>
                </section>

                <EmptyState
                    v-if="competitors.length === 0"
                    :title="$t('app.empty.competitors_title')"
                    :body="$t('app.empty.competitors_body')"
                />
                <div v-else class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.competitors') }}</th>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.aliases') }}</th>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.domains') }}</th>
                                <th class="px-4 py-3 font-medium">{{ $t('app.library.active') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="competitor in competitors"
                                :key="competitor.id"
                                class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                            >
                                <td class="px-4 py-3 font-medium">{{ competitor.name }}</td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.aliases.join(', ') }}</td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.domains.join(', ') }}</td>
                                <td
                                    class="px-4 py-3"
                                    :class="competitor.is_active ? 'text-success' : 'text-shell-muted'"
                                >
                                    {{ competitor.is_active ? $t('app.library.active') : $t('app.library.inactive') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
