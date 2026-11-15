<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Link :href="'/'" class="flex flex-col items-center gap-3 no-underline">
        <span class="flex h-14 w-14 items-center justify-center rounded-xl bg-white">
            <img src="/images/maksimer-m-square.svg" alt="" class="h-8 w-8" />
        </span>
        <span class="text-[15px] font-semibold text-shell-fg">{{ $t('app.name') }}</span>
    </Link>
</template>
