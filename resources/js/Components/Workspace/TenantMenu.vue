<script setup>
import { computed } from 'vue';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth.user);
const team = computed(() => user.value?.current_team ?? null);
const teams = computed(() => (page.props.actingAs ? [] : (user.value?.all_teams ?? [])));
// While a platform admin acts as a tenant, the pill shows that tenant.
const name = computed(() => page.props.actingAs?.tenant ?? team.value?.name ?? '');

function switchTo(id) {
    router.put(route('current-team.update'), { team_id: id }, { preserveState: false });
}

const itemClass = (active) =>
    [
        'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-start text-[13px] no-underline',
        active ? 'bg-row-hover text-shell-fg' : 'text-shell-text',
    ].join(' ');
const headingClass = 'px-2.5 pt-2 pb-1 text-[11px] font-medium tracking-[0.08em] text-shell-muted uppercase';
</script>

<template>
    <Menu v-if="page.props.jetstream?.hasTeamFeatures && team" as="div" class="min-w-0">
        <MenuButton
            class="flex max-w-36 items-center gap-1 rounded-full border border-line-soft px-2.5 py-0.5 text-xs text-shell-muted hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            data-testid="tenant-menu"
        >
            <span class="sr-only">{{ $t('workspace.shell.tenant') }}:</span>
            <span class="truncate">{{ name }}</span>
            <ChevronDownIcon class="size-3.5 flex-none" aria-hidden="true" />
        </MenuButton>
        <MenuItems
            class="absolute inset-x-3 top-12 z-40 max-h-96 overflow-y-auto rounded-xl border border-line bg-shell-surface p-1 shadow-lg focus:outline-none"
        >
            <template v-if="teams.length > 1">
                <div :class="headingClass">{{ $t('workspace.shell.switch_tenant') }}</div>
                <MenuItem v-for="item in teams" :key="item.id" v-slot="{ active }">
                    <button type="button" :class="itemClass(active)" @click="switchTo(item.id)">
                        <span class="truncate">{{ item.name }}</span>
                        <span
                            v-if="item.id == user.current_team_id"
                            class="h-1.5 w-1.5 flex-none rounded-full bg-primary"
                        >
                            <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                        </span>
                    </button>
                </MenuItem>
                <div class="my-1 border-t border-line-soft" />
            </template>
            <MenuItem v-slot="{ active }">
                <Link :href="route('teams.show', team)" :class="itemClass(active)">{{ $t('Team Settings') }}</Link>
            </MenuItem>
            <MenuItem v-if="page.props.jetstream.canCreateTeams" v-slot="{ active }">
                <Link :href="route('teams.create')" :class="itemClass(active)">{{ $t('Create New Team') }}</Link>
            </MenuItem>
        </MenuItems>
    </Menu>
</template>
