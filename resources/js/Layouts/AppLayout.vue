<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import ActingAsBanner from '@/Components/ActingAsBanner.vue';
import Banner from '@/Components/Banner.vue';
import BottomSheet from '@/Components/UI/BottomSheet.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import { useLocale } from '@/Composables/useLocale';
import { applyTheme, currentTheme } from '@/theme';

defineProps({
    title: String,
});

const page = usePage();
const menuOpen = ref(false);
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

const theme = ref(currentTheme());
const themes = [
    { code: 'dark', label: () => trans('app.nav.themes.dark') },
    { code: 'light', label: () => trans('app.nav.themes.light') },
    { code: 'system', label: () => trans('app.nav.themes.system') },
];

const setTheme = (option) => {
    theme.value = option;
    applyTheme(option);
    window.dispatchEvent(new CustomEvent('ekko:theme', { detail: option }));
};

const switchToTeam = (team) => {
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
};

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />

        <ActingAsBanner />

        <Banner />

        <div class="min-h-screen bg-shell text-shell-text">
            <nav class="sticky top-0 z-30 border-b border-shell-fg/10 bg-shell/90 backdrop-blur">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex items-center">
                            <Link :href="route('dashboard')" class="flex items-center gap-2.5 no-underline">
                                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-white">
                                    <img src="/images/maksimer-m-square.svg" alt="" class="h-[19px] w-[19px]" />
                                </span>
                                <span class="text-[15px] font-semibold text-shell-fg">{{ $t('app.name') }}</span>
                            </Link>

                            <div class="ms-8 hidden sm:flex">
                                <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                    {{ $t('Dashboard') }}
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <div
                                v-if="$page.props.jetstream.hasTeamFeatures && user.current_team"
                                class="relative ms-3"
                            >
                                <Dropdown align="right" width="60">
                                    <template #trigger>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-lg border border-shell-fg/10 bg-shell-fg/[.04] px-3 py-2 text-sm text-shell-fg hover:bg-shell-fg/[.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60"
                                        >
                                            <span class="max-w-40 truncate">{{ user.current_team.name }}</span>
                                            <ChevronUpDownIcon class="size-4 flex-none text-shell-text/40" />
                                        </button>
                                    </template>

                                    <template #content>
                                        <div class="w-60">
                                            <div class="block px-4 py-2 text-xs text-dark-beige">
                                                {{ $t('Manage Team') }}
                                            </div>

                                            <DropdownLink :href="route('teams.show', user.current_team)">
                                                {{ $t('Team Settings') }}
                                            </DropdownLink>

                                            <DropdownLink
                                                v-if="$page.props.jetstream.canCreateTeams"
                                                :href="route('teams.create')"
                                            >
                                                {{ $t('Create New Team') }}
                                            </DropdownLink>

                                            <template v-if="user.all_teams.length > 1">
                                                <div class="border-t border-line-soft" />

                                                <div class="block px-4 py-2 text-xs text-dark-beige">
                                                    {{ $t('Switch Teams') }}
                                                </div>

                                                <form
                                                    v-for="team in user.all_teams"
                                                    :key="team.id"
                                                    @submit.prevent="switchToTeam(team)"
                                                >
                                                    <DropdownLink as="button">
                                                        <div class="flex items-center justify-between gap-3">
                                                            <span class="truncate">{{ team.name }}</span>
                                                            <span
                                                                v-if="team.id == user.current_team_id"
                                                                class="h-1.5 w-1.5 flex-none rounded-full bg-primary"
                                                            ></span>
                                                        </div>
                                                    </DropdownLink>
                                                </form>
                                            </template>
                                        </div>
                                    </template>
                                </Dropdown>
                            </div>

                            <div class="relative ms-3">
                                <Dropdown align="right" width="60">
                                    <template #trigger>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-shell-fg hover:bg-shell-fg/[.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60"
                                        >
                                            <span
                                                class="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-full bg-secondary text-[13px] font-semibold text-white"
                                            >
                                                {{ initials }}
                                            </span>
                                            <ChevronUpDownIcon class="size-4 text-shell-text/40" />
                                        </button>
                                    </template>

                                    <template #content>
                                        <div class="block px-4 py-2 text-xs text-dark-beige">
                                            {{ $t('Manage Account') }}
                                        </div>

                                        <DropdownLink :href="route('profile.show')">{{ $t('Profile') }}</DropdownLink>

                                        <DropdownLink
                                            v-if="$page.props.jetstream.hasApiFeatures"
                                            :href="route('api-tokens.index')"
                                        >
                                            {{ $t('API Tokens') }}
                                        </DropdownLink>

                                        <DropdownLink :href="route('mcp.connections')">
                                            {{ $t('mcp.nav') }}
                                        </DropdownLink>

                                        <div class="border-t border-line-soft" />

                                        <div
                                            class="px-4 py-2 text-[11px] font-semibold tracking-[.07em] text-dark-beige uppercase"
                                        >
                                            {{ $t('app.nav.theme') }}
                                        </div>
                                        <button
                                            v-for="option in themes"
                                            :key="option.code"
                                            type="button"
                                            class="flex w-full items-center justify-between px-4 py-1.5 text-start text-sm text-ink-soft hover:bg-light-beige hover:text-ink"
                                            @click="setTheme(option.code)"
                                        >
                                            {{ option.label() }}
                                            <span
                                                v-if="option.code === theme"
                                                class="h-1.5 w-1.5 rounded-full bg-primary"
                                            ></span>
                                        </button>

                                        <div class="border-t border-line-soft" />

                                        <div
                                            class="px-4 py-2 text-[11px] font-semibold tracking-[.07em] text-dark-beige uppercase"
                                        >
                                            {{ $t('app.nav.language') }}
                                        </div>
                                        <button
                                            v-for="item in locales"
                                            :key="item.code"
                                            type="button"
                                            class="flex w-full items-center justify-between px-4 py-1.5 text-start text-sm text-ink-soft hover:bg-light-beige hover:text-ink"
                                            @click="setLocale(item.code)"
                                        >
                                            {{ item.label }}
                                            <span
                                                v-if="item.code === locale"
                                                class="h-1.5 w-1.5 rounded-full bg-primary"
                                            ></span>
                                        </button>

                                        <div class="border-t border-line-soft" />

                                        <form @submit.prevent="logout">
                                            <DropdownLink as="button">{{ $t('Log Out') }}</DropdownLink>
                                        </form>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                type="button"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg text-shell-text/70 hover:bg-shell-fg/[.06] hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60"
                                :aria-label="$t('app.nav.open_menu')"
                                :aria-expanded="menuOpen"
                                @click="menuOpen = true"
                            >
                                <svg
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                >
                                    <line x1="3" y1="6" x2="21" y2="6" />
                                    <line x1="3" y1="12" x2="21" y2="12" />
                                    <line x1="3" y1="18" x2="21" y2="18" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            <BottomSheet :show="menuOpen" :title="$t('app.nav.menu')" @close="menuOpen = false">
                <div class="flex flex-col gap-1 py-2">
                    <Link
                        :href="route('dashboard')"
                        class="rounded-lg px-3 py-2.5 text-sm no-underline"
                        :class="route().current('dashboard') ? 'bg-light-beige font-medium text-ink' : 'text-ink-soft'"
                        @click="menuOpen = false"
                    >
                        {{ $t('Dashboard') }}
                    </Link>
                    <Link
                        :href="route('profile.show')"
                        class="rounded-lg px-3 py-2.5 text-sm text-ink-soft no-underline"
                        @click="menuOpen = false"
                    >
                        {{ $t('Profile') }}
                    </Link>
                    <Link
                        v-if="$page.props.jetstream.hasApiFeatures"
                        :href="route('api-tokens.index')"
                        class="rounded-lg px-3 py-2.5 text-sm text-ink-soft no-underline"
                        @click="menuOpen = false"
                    >
                        {{ $t('API Tokens') }}
                    </Link>
                    <Link
                        :href="route('mcp.connections')"
                        class="rounded-lg px-3 py-2.5 text-sm text-ink-soft no-underline"
                        @click="menuOpen = false"
                    >
                        {{ $t('mcp.nav') }}
                    </Link>
                </div>

                <div
                    v-if="$page.props.jetstream.hasTeamFeatures && user.current_team"
                    class="border-t border-line-soft py-3"
                >
                    <div class="px-3 pb-1 text-[11px] font-semibold tracking-[.07em] text-dark-beige uppercase">
                        {{ $t('Switch Teams') }}
                    </div>
                    <div class="px-3 py-1.5 text-sm text-ink">{{ user.current_team.name }}</div>
                    <form v-for="team in user.all_teams" :key="team.id" @submit.prevent="switchToTeam(team)">
                        <button
                            v-if="team.id != user.current_team_id"
                            type="submit"
                            class="block w-full rounded-lg px-3 py-2.5 text-start text-sm text-ink-soft hover:bg-light-beige"
                        >
                            {{ team.name }}
                        </button>
                    </form>
                    <Link
                        :href="route('teams.show', user.current_team)"
                        class="block rounded-lg px-3 py-2.5 text-sm text-ink-soft no-underline"
                        @click="menuOpen = false"
                    >
                        {{ $t('Team Settings') }}
                    </Link>
                    <Link
                        v-if="$page.props.jetstream.canCreateTeams"
                        :href="route('teams.create')"
                        class="block rounded-lg px-3 py-2.5 text-sm text-ink-soft no-underline"
                        @click="menuOpen = false"
                    >
                        {{ $t('Create New Team') }}
                    </Link>
                </div>

                <div class="border-t border-line-soft py-3">
                    <div class="px-3 pb-1 text-[11px] font-semibold tracking-[.07em] text-dark-beige uppercase">
                        {{ $t('app.nav.theme') }}
                    </div>
                    <button
                        v-for="option in themes"
                        :key="option.code"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-start text-sm text-ink-soft"
                        @click="setTheme(option.code)"
                    >
                        {{ option.label() }}
                        <span v-if="option.code === theme" class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    </button>
                </div>

                <div class="border-t border-line-soft py-3">
                    <div class="px-3 pb-1 text-[11px] font-semibold tracking-[.07em] text-dark-beige uppercase">
                        {{ $t('app.nav.language') }}
                    </div>
                    <button
                        v-for="item in locales"
                        :key="item.code"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-start text-sm text-ink-soft"
                        @click="setLocale(item.code)"
                    >
                        {{ item.label }}
                        <span v-if="item.code === locale" class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    </button>
                </div>

                <form class="border-t border-line-soft py-3" @submit.prevent="logout">
                    <button type="submit" class="block w-full rounded-lg px-3 py-2.5 text-start text-sm text-ink-soft">
                        {{ $t('Log Out') }}
                    </button>
                </form>
            </BottomSheet>

            <header
                v-if="$slots.header"
                class="border-b border-shell-fg/10 px-4 py-5 text-shell-fg sm:px-6 lg:px-8 [&_h2]:font-medium [&_h2]:tracking-tight [&_h2]:text-shell-fg"
            >
                <div class="mx-auto max-w-7xl">
                    <slot name="header" />
                </div>
            </header>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
