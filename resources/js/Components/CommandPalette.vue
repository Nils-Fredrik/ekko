<script setup>
import {
    Combobox,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { CheckIcon } from '@heroicons/vue/20/solid';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { paletteActions } from './paletteActions.js';

const open = ref(false);
const query = ref('');
// «Bytt marked …» and «Bytt merkevare …» open a list of their own.
const submenu = ref(null);
const input = ref(null);
const results = ref({ brands: [], prompts: [], competitors: [] });
const page = usePage();
let timer;
let controller;

const user = computed(() => page.props.auth?.user ?? null);

function matches(label) {
    const needle = query.value.trim().toLowerCase();

    return needle === '' || label.toLowerCase().includes(needle);
}

// Actions for the workspace (docs/ux-arbeidsflate.md 4.6), shown by `workspace.can`.
const workspaceActions = computed(() =>
    paletteActions({
        workspace: page.props.workspace ?? null,
        url: page.url,
        has: (name) => route().has(name),
        to: (name, params) => route(name, params),
        t: trans,
    }).filter((item) => matches(item.label)),
);

const actions = computed(() => {
    const items = [];
    const teams = user.value?.all_teams ?? [];

    if (teams.length > 1 && !page.props.actingAs) {
        for (const team of teams) {
            if (team.id == user.value.current_team_id) {
                continue;
            }
            items.push({
                id: `team-${team.id}`,
                label: trans('app.palette.switch_team', { name: team.name }),
                teamId: team.id,
            });
        }
    }

    return items.filter((item) => matches(item.label));
});

const pages = computed(() => {
    const items = [
        { id: 'dashboard', label: trans('Dashboard'), href: route('dashboard') },
        { id: 'brands', label: trans('app.nav.brands'), href: route('brands.index') },
        { id: 'alerts', label: trans('app.nav.alerts'), href: route('alerts.index') },
        { id: 'reports', label: trans('app.nav.reports'), href: route('reports.index') },
    ];

    if (page.props.canViewPartner) {
        items.push({ id: 'partner', label: trans('app.nav.partner'), href: route('partner.index') });
    }
    if (page.props.canOnboard) {
        items.push({ id: 'wizard', label: trans('app.nav.wizard'), href: route('onboarding.wizard') });
    }
    if (page.props.canViewUsage) {
        items.push({ id: 'usage', label: trans('app.nav.usage'), href: route('usage.index') });
    }
    if (user.value?.is_platform_admin) {
        items.push(
            { id: 'admin-tenants', label: trans('admin.nav.tenants'), href: route('admin.tenants.index') },
            { id: 'admin-plans', label: trans('admin.nav.plans'), href: route('admin.plans.index') },
            { id: 'admin-engines', label: trans('admin.nav.engines'), href: route('admin.engines.index') },
            { id: 'admin-usage', label: trans('admin.nav.usage'), href: route('admin.usage') },
            { id: 'admin-billing', label: trans('billing.title'), href: route('admin.billing.index') },
        );
    }
    if (user.value) {
        items.push({ id: 'profile', label: trans('Profile'), href: route('profile.show') });
    }

    return items.filter((item) => matches(item.label));
});

const groups = computed(() => {
    if (submenu.value) {
        const back = { id: 'back', label: trans('workspace.palette.back'), back: true };

        return [
            {
                id: 'submenu',
                label: submenu.value.label,
                items: [back, ...submenu.value.children.filter((item) => matches(item.label))],
            },
        ];
    }

    return [
        { id: 'workspace', label: trans('workspace.palette.group_actions'), items: workspaceActions.value },
        { id: 'actions', label: trans('app.palette.groups.actions'), items: actions.value },
        { id: 'pages', label: trans('app.palette.groups.pages'), items: pages.value },
        { id: 'brands', label: trans('app.palette.groups.brands'), items: results.value.brands },
        { id: 'prompts', label: trans('app.palette.groups.prompts'), items: results.value.prompts },
        { id: 'competitors', label: trans('app.palette.groups.competitors'), items: results.value.competitors },
    ].filter((group) => group.items.length > 0);
});

function show() {
    open.value = true;
}

function onKey(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !event.repeat) {
        event.preventDefault();
        open.value = !open.value;
    }
}

function scheduleSearch() {
    clearTimeout(timer);
    timer = setTimeout(search, 150);
}

async function search() {
    const needle = query.value.trim();
    controller?.abort();
    if (needle === '') {
        results.value = { brands: [], prompts: [], competitors: [] };
        return;
    }

    const current = new AbortController();
    controller = current;
    try {
        const response = await fetch(`${route('palette.search')}?q=${encodeURIComponent(needle)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: current.signal,
        });
        if (!response.ok || current.signal.aborted || !open.value) {
            return;
        }
        const payload = await response.json();
        if (current.signal.aborted || !open.value) {
            return;
        }
        results.value = payload;
    } catch (error) {
        if (error.name !== 'AbortError' && open.value) {
            results.value = { brands: [], prompts: [], competitors: [] };
        }
    }
}

function resetQuery() {
    clearTimeout(timer);
    controller?.abort();
    query.value = '';
    results.value = { brands: [], prompts: [], competitors: [] };
    if (input.value?.$el) {
        input.value.$el.value = '';
    }
}

function choose(item) {
    if (!item) {
        return;
    }
    if (item.children || item.back) {
        submenu.value = item.back ? null : item;
        resetQuery();
        return;
    }
    open.value = false;
    // Visit after the dialog closes. A navigation started inside the close handler is cancelled.
    nextTick(() => {
        if (item.teamId) {
            router.put(route('current-team.update'), { team_id: item.teamId }, { preserveState: false });
            return;
        }
        router.visit(item.href);
    });
}

watch(open, (isOpen) => {
    if (!isOpen) {
        clearTimeout(timer);
        controller?.abort();
        query.value = '';
        submenu.value = null;
        results.value = { brands: [], prompts: [], competitors: [] };
    }
});

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(timer);
    controller?.abort();
});

defineExpose({ show });
</script>

<template>
    <div>
        <button
            type="button"
            class="inline-flex items-center rounded-lg border border-shell-fg/10 bg-shell-fg/[.04] px-3 py-2 text-sm text-shell-muted hover:bg-shell-fg/[.07]"
            @click="show"
        >
            {{ $t('app.palette.open') }}
        </button>

        <TransitionRoot :show="open" as="template">
            <Dialog class="relative z-50" @close="open = false">
                <TransitionChild
                    as="template"
                    enter="duration-150 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-100 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/40"></div>
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto p-4 sm:p-16">
                    <DialogPanel
                        class="mx-auto max-w-xl overflow-hidden rounded-2xl border border-line bg-shell-surface text-shell-text shadow-xl"
                    >
                        <Combobox nullable @update:model-value="choose">
                            <ComboboxInput
                                ref="input"
                                class="w-full border-0 bg-transparent px-4 py-3 text-sm text-shell-text placeholder:text-shell-muted focus:ring-0"
                                :placeholder="$t('app.palette.placeholder')"
                                @input="
                                    query = $event.target.value;
                                    scheduleSearch();
                                "
                            />
                            <ComboboxOptions static class="max-h-80 overflow-auto border-t border-line py-2">
                                <p v-if="groups.length === 0" class="px-4 py-3 text-sm text-shell-muted">
                                    {{ $t('app.palette.empty') }}
                                </p>
                                <template v-for="group in groups" :key="group.id">
                                    <p class="px-4 pt-3 pb-1 text-xs text-shell-muted">{{ group.label }}</p>
                                    <ComboboxOption
                                        v-for="item in group.items"
                                        :key="`${group.id}-${item.id}`"
                                        v-slot="{ active }"
                                        :value="item"
                                        as="template"
                                    >
                                        <li
                                            class="mx-2 cursor-pointer rounded-lg px-3 py-2 text-sm"
                                            :class="active ? 'bg-shell-text/10' : ''"
                                        >
                                            <span class="flex items-center gap-2">
                                                <span>{{ item.label }}</span>
                                                <template v-if="item.current">
                                                    <CheckIcon class="h-4 w-4 text-success-text" aria-hidden="true" />
                                                    <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                                                </template>
                                            </span>
                                            <span v-if="item.detail" class="block text-xs text-shell-muted">
                                                {{ item.detail }}
                                            </span>
                                        </li>
                                    </ComboboxOption>
                                </template>
                            </ComboboxOptions>
                        </Combobox>
                    </DialogPanel>
                </div>
            </Dialog>
        </TransitionRoot>
    </div>
</template>
