<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    brandId: { type: Number, required: true },
});

const page = usePage();
function href(item) {
    const query = item.name === 'brands.actions.index' ? {} : (page.props.analysisFilters?.query ?? {});
    return route(item.name, { brand: props.brandId, ...query });
}

const items = [
    { name: 'brands.show', label: 'app.library.overview', match: 'brands.show' },
    { name: 'brands.prompts.index', label: 'app.library.prompts', match: 'brands.prompts.*' },
    { name: 'brands.competitors.index', label: 'app.library.competitors', match: 'brands.competitors.*' },
    { name: 'brands.sources', label: 'app.library.sources', match: 'brands.sources' },
    { name: 'brands.crawlers', label: 'app.library.crawlers', match: 'brands.crawlers' },
    { name: 'brands.actions.index', label: 'app.library.actions', match: 'brands.actions.*' },
];

function active(match) {
    return route().current(match);
}
</script>

<template>
    <nav class="flex flex-wrap gap-2">
        <Link
            v-for="item in items"
            :key="item.name"
            :href="href(item)"
            class="rounded-full px-3 py-1 text-sm no-underline"
            :class="active(item.match) ? 'bg-primary text-white' : 'bg-shell-surface text-shell-text/70'"
        >
            {{ $t(item.label) }}
        </Link>
    </nav>
</template>
