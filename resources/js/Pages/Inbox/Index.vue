<script setup>
// The inbox (docs/ux-arbeidsflate.md 5.6, U26). A treatment drops the row without loading a new page.
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useUndoToast } from '@/Composables/useUndoToast';
import { formatDate, formatNumber, formatWeekday } from '@/format';
import { groupInbox } from '@/inbox/groups';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    items: { type: Array, required: true },
    counts: { type: Object, required: true },
    filters: { type: Object, required: true },
    brands: { type: Array, required: true },
    can: { type: Object, required: true },
});

const toast = useUndoToast();
const hidden = ref(new Set());
const expanded = ref(new Set());
const busy = ref(false);

const tabs = [
    ['all', 'all'],
    ['alerts', 'alert'],
    ['entities', 'entity'],
    ['actions', 'action'],
    ['claims', 'claim'],
    ['integrations', 'integration'],
];
const edges = {
    warning: 'bg-warning',
    error: 'bg-error',
    'medium-beige': 'bg-medium-beige',
    'marker-action': 'bg-[var(--data-marker-action)]',
    'marker-model': 'bg-[var(--data-marker-model)]',
};
const needs = {
    acknowledge: 'alerts',
    acknowledge_all: 'alerts',
    plan: 'actions',
    decline: 'actions',
    create_action: 'claims',
    competitor: 'entities',
    other: 'entities',
    ignored: 'entities',
    alias: 'entities',
};

const visibleItems = computed(() => props.items.filter((item) => !hidden.value.has(item.id)));
const groups = computed(() =>
    groupInbox(visibleItems.value, {
        heading(at, distance) {
            if (distance === 0) {
                return trans('workspace.inbox.today');
            }
            if (distance === -1) {
                return trans('workspace.inbox.yesterday');
            }

            return `${formatWeekday(at)} ${formatDate(at)}`;
        },
    }),
);

function allowed(action) {
    const gate = needs[action.key];

    return gate === undefined || props.can[gate] === true;
}

function conceal(id) {
    hidden.value = new Set([...hidden.value, id]);
}

function reveal(id) {
    const next = new Set(hidden.value);
    next.delete(id);
    hidden.value = next;
}

function query(changes) {
    hidden.value = new Set();
    const next = {
        brand: props.filters.brand || undefined,
        market: props.filters.market || undefined,
        type: props.filters.type === 'all' ? undefined : props.filters.type,
        handled: props.filters.handled ? '1' : undefined,
        ...changes,
    };
    router.get(route('inbox.index'), next, {
        preserveState: true,
        preserveScroll: true,
        only: ['items', 'counts', 'filters', 'workspace'],
    });
}

function reload() {
    return new Promise((resolve, reject) => {
        let settled = false;
        router.reload({
            preserveState: true,
            preserveScroll: true,
            only: ['items', 'counts', 'workspace', 'flash'],
            onSuccess: (visit) => {
                settled = true;
                resolve(visit);
            },
            onError: () => {
                settled = true;
                reject(new Error('save'));
            },
            onFinish: () => {
                if (!settled) {
                    reject(new Error('finish'));
                }
            },
        });
    });
}

function request(method, url, data) {
    return new Promise((resolve, reject) => {
        let settled = false;
        const options = {
            preserveState: true,
            preserveScroll: true,
            only: ['items', 'counts', 'workspace', 'flash'],
            onSuccess: (visit) => {
                settled = true;
                resolve(visit);
            },
            onError: () => {
                settled = true;
                reject(new Error('save'));
            },
            onCancel: () => {
                settled = true;
                reject(new Error('cancel'));
            },
            onFinish: () => {
                if (!settled) {
                    reject(new Error('finish'));
                }
            },
        };
        if (method === 'delete') {
            router.delete(url, { ...options, data });
        } else {
            router[method](url, data, options);
        }
    });
}

async function act(item, action) {
    if (action.key === 'show_all') {
        const next = new Set(expanded.value);
        if (next.has(item.id)) {
            next.delete(item.id);
        } else {
            next.add(item.id);
        }
        expanded.value = next;

        return;
    }
    if (action.key === 'open' || action.key === 'open_integration') {
        router.visit(item.target_url);

        return;
    }
    const withoutBrand = action.key === 'acknowledge' || action.key === 'acknowledge_all';
    if (busy.value || (!item.brand && !withoutBrand)) {
        return;
    }
    if (action.key === 'alias' && !item.suggestion?.target_id) {
        toast.show(trans('workspace.inbox.failed'));

        return;
    }
    busy.value = true;
    conceal(item.id);
    try {
        if (action.key === 'acknowledge' || action.key === 'acknowledge_all') {
            const visit = await request('post', route('alerts.acknowledge.bulk'), { ids: item.source_ids });
            const changed = visit.props?.flash?.acknowledged_ids ?? item.source_ids;
            toast.show(trans('workspace.inbox.acknowledged'), async () => {
                await request('delete', route('alerts.unacknowledge.bulk'), { ids: changed });
                reveal(item.id);
            });
        } else if (action.key === 'plan' || action.key === 'decline') {
            await request('patch', route('brands.actions.update', [item.brand.id, item.source_ids[0]]), {
                status: action.key === 'plan' ? 'planned' : 'declined',
            });
            toast.show(trans('workspace.inbox.handled_row'));
        } else if (action.key === 'create_action') {
            await request('post', route('brands.claims.action', [item.brand.id, item.source_ids[0]]), {});
            toast.show(trans('workspace.inbox.action_created'));
        } else {
            const body = { keys: item.source_ids, decision: action.key };
            if (action.key === 'alias') {
                body.target_type = item.suggestion.target_type;
                body.target_id = item.suggestion.target_id;
            }
            const response = await axios.post(route('brands.entities.decide', item.brand.id), body);
            const undoIds = response.data?.undo_ids ?? [];
            await reload();
            toast.show(trans('workspace.inbox.decided'), async () => {
                await Promise.all(
                    undoIds.map((id) => axios.delete(route('brands.entities.undo', [item.brand.id, id]))),
                );
                reveal(item.id);
                await reload();
            });
        }
    } catch {
        reveal(item.id);
        toast.show(trans('workspace.inbox.failed'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout :title="$t('workspace.shell.inbox')">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6 sm:px-6">
            <WorkspaceHeader
                :brand="brands.find((item) => item.id === Number(filters.brand))?.name"
                :group="$t('workspace.shell.work')"
                :title="$t('workspace.inbox.title', { count: formatNumber(counts.all) })"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-sm text-shell-text">
                        <span class="sr-only">{{ $t('workspace.inbox.brand') }}</span>
                        <select
                            class="rounded-full border-line bg-shell-surface py-1.5 text-sm"
                            :value="filters.brand ?? ''"
                            data-testid="inbox-brand"
                            @change="query({ brand: $event.target.value || undefined, market: undefined })"
                        >
                            <option value="">{{ $t('workspace.inbox.all_brands') }}</option>
                            <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-shell-text">
                        <input
                            type="checkbox"
                            class="rounded border-line"
                            :checked="filters.handled"
                            data-testid="inbox-handled"
                            @change="query({ handled: $event.target.checked ? '1' : undefined })"
                        />
                        {{ $t('workspace.inbox.handled') }}
                    </label>
                </div>
            </WorkspaceHeader>

            <div
                class="flex gap-4 overflow-x-auto border-b border-line-soft"
                role="group"
                :aria-label="$t('workspace.shell.inbox')"
            >
                <button
                    v-for="[id, countKey] in tabs"
                    :key="id"
                    type="button"
                    class="border-b-2 px-1 pb-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="
                        filters.type === id ? 'border-primary text-shell-fg' : 'border-transparent text-shell-muted'
                    "
                    :aria-pressed="filters.type === id"
                    :data-testid="`inbox-tab-${id}`"
                    @click="query({ type: id === 'all' ? undefined : id })"
                >
                    {{ $t(`workspace.inbox.tabs.${id}`) }}
                    <span class="tabular-nums" :data-testid="`inbox-count-${id}`">
                        {{ formatNumber(counts[countKey] ?? 0) }}
                    </span>
                </button>
            </div>

            <p v-if="visibleItems.length === 0" class="text-sm text-shell-muted" data-testid="inbox-empty">
                {{ $t('workspace.inbox.empty') }}
            </p>

            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-2">
                <h2 class="text-xs font-medium tracking-[0.08em] text-shell-muted uppercase">{{ group.label }}</h2>
                <article
                    v-for="item in group.items"
                    :key="item.id"
                    class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-3 rounded-[14px] border border-line-soft bg-shell-surface p-3 sm:flex"
                    :data-testid="`inbox-row-${item.type}`"
                >
                    <span
                        class="mt-1 h-8 w-1 shrink-0 rounded"
                        :class="edges[item.edge] || 'bg-warning'"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-[11.5px] text-shell-muted">{{ $t(`workspace.inbox.types.${item.type}`) }}</p>
                        <h3 class="text-sm text-shell-fg">{{ item.title }}</h3>
                        <p class="text-[12.5px] text-shell-muted">{{ item.detail }}</p>
                        <ul v-if="expanded.has(item.id) && item.members?.length" class="mt-2 space-y-1">
                            <li v-for="member in item.members" :key="member.id" class="text-xs text-shell-text">
                                {{ member.title }}
                                <span class="text-shell-muted">{{ member.detail }}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="col-span-2 flex flex-wrap gap-2 sm:shrink-0 sm:justify-end">
                        <button
                            v-for="action in item.actions.filter(allowed)"
                            :key="action.key"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :class="
                                action.primary ? 'bg-primary-deep text-white' : 'border border-line text-shell-text'
                            "
                            :data-testid="`inbox-${action.key}`"
                            @click="act(item, action)"
                        >
                            {{ action.label }}
                        </button>
                    </div>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
