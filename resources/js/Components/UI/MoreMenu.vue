<script setup>
import { Menu, MenuButton, MenuItems } from '@headlessui/vue';
import { EllipsisHorizontalIcon } from '@heroicons/vue/20/solid';

defineProps({
    label: { type: String, required: true },
    align: { type: String, default: 'end' },
});
</script>

<template>
    <Menu as="div" class="relative">
        <MenuButton
            :aria-label="label"
            :title="label"
            class="flex h-8 w-8 items-center justify-center rounded-full text-shell-muted hover:bg-row-hover hover:text-shell-fg"
        >
            <EllipsisHorizontalIcon class="h-5 w-5" aria-hidden="true" />
        </MenuButton>
        <MenuItems
            :class="align === 'start' ? 'start-0' : 'end-0'"
            class="absolute top-full z-30 mt-1 w-max max-w-48 rounded-xl border border-line bg-shell-surface p-1 shadow-pop focus:outline-none"
        >
            <slot />
        </MenuItems>
    </Menu>
</template>
