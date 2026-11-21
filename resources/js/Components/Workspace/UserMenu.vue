<script setup>
import { computed, ref } from 'vue';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { useLocale } from '@/Composables/useLocale';
import { useWorkspace } from '@/Composables/useWorkspace';
import { applyTheme, currentTheme } from '@/theme';

const page = usePage();
const { workspace } = useWorkspace();
const { locale, locales, setLocale } = useLocale();

const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    (user.value?.name || '?')
        .split(' ')
        .map((part) => part.charAt(0))
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);
const role = computed(() => {
    if (workspace.value?.role) {
        return trans(workspace.value.role);
    }

    return user.value?.is_platform_admin ? trans('workspace.shell.platform_admin') : '';
});

const theme = ref(currentTheme());
const themes = [
    { code: 'dark', label: () => trans('app.nav.themes.dark') },
    { code: 'light', label: () => trans('app.nav.themes.light') },
    { code: 'system', label: () => trans('app.nav.themes.system') },
];

function setTheme(option) {
    theme.value = option;
    applyTheme(option);
    window.dispatchEvent(new CustomEvent('ekko:theme', { detail: option }));
}

const links = computed(() => {
    const items = [{ href: route('profile.show'), label: trans('Profile') }];
    if (page.props.jetstream?.hasApiFeatures) {
        items.push({ href: route('api-tokens.index'), label: trans('API Tokens') });
    }
    items.push({ href: route('mcp.connections'), label: trans('mcp.nav') });

    return items;
});
const adminLinks = computed(() =>
    user.value?.is_platform_admin
        ? [
              { href: route('admin.tenants.index'), label: trans('admin.nav.tenants') },
              { href: route('admin.plans.index'), label: trans('admin.nav.plans') },
              { href: route('admin.engines.index'), label: trans('admin.nav.engines') },
              { href: route('admin.usage'), label: trans('admin.nav.usage') },
              { href: route('admin.billing.index'), label: trans('billing.title') },
          ]
        : [],
);

const logout = () => router.post(route('logout'));

const itemClass = (active) =>
    [
        'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-start text-[13px] no-underline',
        active ? 'bg-row-hover text-shell-fg' : 'text-shell-text',
    ].join(' ');
const headingClass = 'px-2.5 pt-2 pb-1 text-[11px] font-medium tracking-[0.08em] text-shell-muted uppercase';
</script>

<template>
    <Menu as="div" class="relative">
        <MenuButton
            data-testid="account-menu"
            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start hover:bg-row-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
            <span
                class="flex size-7 flex-none items-center justify-center rounded-full bg-line-soft text-[11px] font-medium text-shell-text"
                aria-hidden="true"
            >
                {{ initials }}
            </span>
            <span class="flex min-w-0 flex-col">
                <span class="truncate text-[13px] text-shell-fg">{{ user.name }}</span>
                <span v-if="role" class="truncate text-xs text-shell-muted">{{ role }}</span>
            </span>
            <span class="sr-only">{{ $t('workspace.shell.account') }}</span>
        </MenuButton>
        <MenuItems
            class="absolute inset-x-0 bottom-full z-40 mb-1 max-h-[70vh] overflow-y-auto rounded-xl border border-line bg-shell-surface p-1 shadow-lg focus:outline-none"
        >
            <MenuItem v-for="item in links" :key="item.href" v-slot="{ active }">
                <Link :href="item.href" :class="itemClass(active)">{{ item.label }}</Link>
            </MenuItem>
            <template v-if="adminLinks.length">
                <div :class="headingClass">{{ $t('workspace.shell.platform_admin') }}</div>
                <MenuItem v-for="item in adminLinks" :key="item.href" v-slot="{ active }">
                    <Link :href="item.href" :class="itemClass(active)">{{ item.label }}</Link>
                </MenuItem>
            </template>
            <div class="my-1 border-t border-line-soft" />
            <div :class="headingClass">{{ $t('app.nav.theme') }}</div>
            <MenuItem v-for="option in themes" :key="option.code" v-slot="{ active }">
                <button type="button" :class="itemClass(active)" @click="setTheme(option.code)">
                    {{ option.label() }}
                    <span v-if="option.code === theme" class="h-1.5 w-1.5 rounded-full bg-primary">
                        <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                    </span>
                </button>
            </MenuItem>
            <div :class="headingClass">{{ $t('app.nav.language') }}</div>
            <MenuItem v-for="item in locales" :key="item.code" v-slot="{ active }">
                <button type="button" :class="itemClass(active)" @click="setLocale(item.code)">
                    {{ item.label }}
                    <span v-if="item.code === locale" class="h-1.5 w-1.5 rounded-full bg-primary">
                        <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                    </span>
                </button>
            </MenuItem>
            <div class="my-1 border-t border-line-soft" />
            <MenuItem v-slot="{ active }">
                <button type="button" :class="itemClass(active)" @click="logout">{{ $t('Log Out') }}</button>
            </MenuItem>
        </MenuItems>
    </Menu>
</template>
