<script setup>
import { Link } from '@inertiajs/vue3';

const links = [
    ['admin.tenants.index', 'admin.nav.tenants'],
    ['admin.plans.index', 'admin.nav.plans'],
    ['admin.engines.index', 'admin.nav.engines'],
    ['admin.usage', 'admin.nav.usage'],
    ['admin.billing.index', 'billing.title'],
];
</script>

<template>
    <nav class="flex flex-wrap gap-2">
        <Link
            v-for="[name, label] in links"
            :key="name"
            :href="route(name)"
            class="rounded-full px-3 py-1.5 text-sm no-underline"
            :class="route().current(name) ? 'bg-primary font-medium text-white' : 'border border-line text-shell-muted'"
        >
            {{ $t(label) }}
        </Link>
    </nav>
</template>
