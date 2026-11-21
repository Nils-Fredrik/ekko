<script setup>
// Shared components (docs/ux-arbeidsflate.md 4.2–4.5) with fixed, synthetic test data. The route exists
// only in local and testing (routes/dev.php). Saving and archiving stay in the browser: there is no
// server endpoint behind this page.
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import BulkBar from '@/Components/UI/BulkBar.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import Drawer from '@/Components/UI/Drawer.vue';
import SaveStatus from '@/Components/UI/SaveStatus.vue';
import { SaveError, useAutosave } from '@/Composables/useAutosave';
import { useOpenRow } from '@/Composables/useOpenRow';
import { useUndoToast } from '@/Composables/useUndoToast';
import {
    formatCount,
    formatCurrency,
    formatDate,
    formatDateTime,
    formatDecimal,
    formatNumber,
    formatPercent,
    formatPoints,
    formatRelative,
} from '@/format';
import AppLayout from '@/Layouts/AppLayout.vue';

const MARKETS = ['no', 'se', 'dk'];
// The browser tests freeze the clock at this moment, so relative dates look the same on every run.
const REFERENCE = Date.UTC(2026, 9, 16, 12);
const HOUR = 3_600_000;

function makeRows(count) {
    return Array.from({ length: count }, (_, index) => {
        const n = index + 1;

        return {
            id: n,
            topic_id: (index % 4) + 1,
            market: MARKETS[index % MARKETS.length],
            visibility: ((n * 37) % 100) / 100,
            change: (((n * 13) % 21) - 10) / 1000,
            answers: (n * 53) % 1500,
            measured_at: new Date(REFERENCE - (n % 9) * 24 * HOUR - (n % 2) * 9 * HOUR).toISOString(),
            core: n % 5 === 0,
            text: null,
        };
    });
}

const promptText = (row) => row.text ?? trans('workspace.dev.prompt_n', { n: row.id });

// Format examples follow the language (format.js settings are reactive).
const formatExamples = computed(() => [
    ['formatNumber(1412)', formatNumber(1412)],
    ['formatDecimal(2.64, 1)', formatDecimal(2.64, 1)],
    ["formatCurrency(16.5374371, 'USD')", formatCurrency(16.5374371, 'USD')],
    ["formatCurrency(0.0123456, 'USD', { unit: true })", formatCurrency(0.0123456, 'USD', { unit: true })],
    ['formatPercent(0.3812)', formatPercent(0.3812)],
    ['formatPoints(-0.031)', formatPoints(-0.031)],
    ["formatDate('2026-10-01')", formatDate('2026-10-01')],
    ["formatDate('2025-11-01')", formatDate('2025-11-01')],
    ["formatDateTime('2026-10-16T00:14:00Z')", formatDateTime('2026-10-16T00:14:00Z')],
    ["formatRelative('2026-10-16T01:30:00Z')", formatRelative('2026-10-16T01:30:00Z')],
    ["formatRelative('2026-10-15')", formatRelative('2026-10-15')],
    ["formatRelative('2026-10-19')", formatRelative('2026-10-19')],
    ["formatCount(1, 'answers')", formatCount(1, 'answers')],
    ["formatCount(412, 'answers')", formatCount(412, 'answers')],
]);

// The main table: grouped by topic, multi-select, detail panel.
const rows = ref(makeRows(48));
const selection = ref(new Set());
const query = ref('');
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return needle === '' ? rows.value : rows.value.filter((row) => promptText(row).toLowerCase().includes(needle));
});
const columns = computed(() => [
    {
        id: 'text',
        header: trans('workspace.dev.columns.text'),
        accessor: 'id',
        width: 'minmax(260px, 3fr)',
        primary: true,
        sortable: true,
    },
    { id: 'market', header: trans('workspace.dev.columns.market'), width: '120px', hideable: true },
    {
        id: 'visibility',
        header: trans('workspace.dev.columns.visibility'),
        width: '110px',
        align: 'end',
        sortable: true,
        hideable: true,
    },
    {
        id: 'change',
        header: trans('workspace.dev.columns.change'),
        width: '110px',
        align: 'end',
        sortable: true,
        hideable: true,
    },
    {
        id: 'answers',
        header: trans('workspace.dev.columns.answers'),
        width: '110px',
        align: 'end',
        sortable: true,
        hideable: true,
    },
    {
        id: 'measured_at',
        header: trans('workspace.dev.columns.measured'),
        width: '140px',
        sortable: true,
        hideable: true,
    },
]);

const { openId, open, close } = useOpenRow();
const openRow = computed(() => rows.value.find((row) => row.id === openId.value) ?? null);
const tab = ref('results');
const failNext = ref(false);
const values = reactive({ text: null, market: 'no', core: false });

// Stands in for the server: answers after a short wait, or fails once when asked to.
function fakeRequest(method, url, data) {
    return new Promise((resolve, reject) => {
        setTimeout(() => {
            if (failNext.value) {
                failNext.value = false;
                const [field] = Object.keys(data);
                reject(new SaveError({ [field]: trans('workspace.save.failed') }));
                return;
            }
            const row = rows.value.find((item) => `/dev/components/${item.id}` === url);
            if (row) {
                Object.assign(row, data);
            }
            resolve();
        }, 300);
    });
}

const autosave = useAutosave(() => `/dev/components/${openId.value}`, { values, request: fakeRequest });

watch(
    openRow,
    (row) => {
        autosave.reset();
        if (row) {
            values.text = row.text;
            values.market = row.market;
            values.core = row.core;
        }
    },
    { immediate: true },
);

function saveText(event) {
    const text = event.target.value.trim();
    autosave.save(
        'text',
        text === '' || text === trans('workspace.dev.prompt_n', { n: openRow.value.id }) ? null : text,
    );
}

const toast = useUndoToast();

function archive() {
    const ids = new Set(selection.value);
    const removed = rows.value.filter((row) => ids.has(row.id));
    rows.value = rows.value.filter((row) => !ids.has(row.id));
    selection.value = new Set();
    if (ids.has(openId.value)) {
        close();
    }
    toast.show(trans('workspace.dev.archived', { count: formatNumber(removed.length) }), async () => {
        rows.value = [...rows.value, ...removed].sort((a, b) => a.id - b.id);
    });
}

// A long table renders only the rows in view.
const manyRows = makeRows(1200);
const manySelection = ref(new Set());
const manyColumns = computed(() => [
    {
        id: 'name',
        header: trans('workspace.dev.columns.text'),
        accessor: 'id',
        width: 'minmax(200px, 2fr)',
        sortable: true,
    },
    { id: 'market', header: trans('workspace.dev.columns.market'), width: '140px' },
    { id: 'answers', header: trans('workspace.dev.columns.answers'), width: '120px', align: 'end', sortable: true },
]);

const statusStates = ['idle', 'saving', 'saved', 'error'];
</script>

<template>
    <AppLayout :title="$t('workspace.dev.title')">
        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <div>
                <h1 class="text-xl font-semibold text-shell-fg">{{ $t('workspace.dev.title') }}</h1>
                <p class="mt-1 text-sm text-shell-muted">{{ $t('workspace.dev.intro') }}</p>
            </div>

            <section class="min-w-0 space-y-3">
                <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.format_title') }}</h2>
                <div class="overflow-x-auto rounded-[14px] border border-line">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-[11.5px] text-shell-muted">
                            <tr class="border-b border-line">
                                <th scope="col" class="px-3 py-2 font-normal">
                                    {{ $t('workspace.dev.format_function') }}
                                </th>
                                <th scope="col" class="px-3 py-2 font-normal">
                                    {{ $t('workspace.dev.format_result') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="[call, result] in formatExamples"
                                :key="call"
                                class="border-b border-line-soft last:border-b-0"
                            >
                                <td class="px-3 py-2 font-mono text-xs text-shell-muted">{{ call }}</td>
                                <td class="px-3 py-2 tabular-nums text-shell-text">{{ result }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="min-w-0 space-y-3">
                <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.table_title') }}</h2>
                <div class="flex items-start gap-4">
                    <div class="min-w-0 flex-[2_1_0] space-y-3">
                        <DataTable
                            v-model:selection="selection"
                            :rows="filtered"
                            :columns="columns"
                            :label="$t('workspace.dev.table_label')"
                            :row-label="promptText"
                            group-by="topic_id"
                            :active-row="openId"
                            column-store-key="dev.components"
                            @open="open"
                            @close="close"
                        >
                            <template #toolbar>
                                <input
                                    v-model="query"
                                    type="search"
                                    class="w-full max-w-sm rounded-full border-line bg-shell-surface px-4 py-1.5 text-sm text-shell-text placeholder:text-shell-muted"
                                    :aria-label="$t('workspace.dev.search')"
                                    :placeholder="$t('workspace.dev.search')"
                                />
                            </template>
                            <template #group="{ group }">
                                <span>{{ $t('workspace.dev.topic_n', { n: group.key }) }}</span>
                            </template>
                            <template #cell-text="{ row }">
                                <span class="block truncate">{{ promptText(row) }}</span>
                            </template>
                            <template #cell-market="{ row }">
                                <span class="truncate text-shell-muted">
                                    {{ $t(`workspace.dev.markets.${row.market}`) }}
                                </span>
                            </template>
                            <template #cell-visibility="{ value }">{{ formatPercent(value) }}</template>
                            <template #cell-change="{ value }">
                                <span
                                    :class="
                                        value > 0
                                            ? 'text-success-text'
                                            : value < 0
                                              ? 'text-error-text'
                                              : 'text-shell-muted'
                                    "
                                >
                                    {{ formatPoints(value) }}
                                </span>
                            </template>
                            <template #cell-answers="{ value }">{{ formatNumber(value) }}</template>
                            <template #cell-measured_at="{ value }">
                                <span class="truncate text-shell-muted">{{ formatRelative(value) }}</span>
                            </template>
                        </DataTable>
                        <BulkBar :count="selection.size" @clear="selection = new Set()">
                            <button
                                type="button"
                                class="rounded-full border border-line px-3 py-1 text-sm text-error-text hover:bg-shell-surface"
                                @click="archive"
                            >
                                {{ $t('workspace.dev.bulk_archive') }}
                            </button>
                        </BulkBar>
                    </div>

                    <Drawer
                        v-model:tab="tab"
                        :open="openRow !== null"
                        :label="
                            openRow
                                ? $t('workspace.dev.drawer_label', {
                                      market: $t(`workspace.dev.markets.${openRow.market}`),
                                  })
                                : ''
                        "
                        :save-state="autosave.state.value"
                        :save-error="autosave.error.value"
                        :tabs="[
                            { id: 'results', label: $t('workspace.dev.tabs.results') },
                            { id: 'answers', label: $t('workspace.dev.tabs.answers') },
                            { id: 'history', label: $t('workspace.dev.tabs.history') },
                        ]"
                        @close="close"
                    >
                        <template #default="{ tab: current }">
                            <div v-if="openRow && current === 'results'" class="space-y-4 text-sm">
                                <label class="block">
                                    <span class="text-xs text-shell-muted">{{ $t('workspace.dev.field_text') }}</span>
                                    <textarea
                                        rows="3"
                                        class="mt-1 w-full rounded-lg border-line bg-shell-panel text-sm text-shell-text"
                                        :value="values.text ?? $t('workspace.dev.prompt_n', { n: openRow.id })"
                                        :aria-invalid="autosave.status('text') === 'error' || undefined"
                                        @blur="saveText"
                                        @keydown.enter.prevent="$event.target.blur()"
                                    ></textarea>
                                    <span v-if="autosave.errors.text" class="mt-1 block text-xs text-error-text">
                                        {{ autosave.errors.text }}
                                    </span>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-shell-muted">{{ $t('workspace.dev.field_market') }}</span>
                                    <select
                                        class="mt-1 w-full rounded-lg border-line bg-shell-panel text-sm text-shell-text"
                                        :value="values.market"
                                        @change="autosave.save('market', $event.target.value)"
                                    >
                                        <option v-for="market in MARKETS" :key="market" :value="market">
                                            {{ $t(`workspace.dev.markets.${market}`) }}
                                        </option>
                                    </select>
                                    <span v-if="autosave.errors.market" class="mt-1 block text-xs text-error-text">
                                        {{ autosave.errors.market }}
                                    </span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        class="rounded border-line bg-shell-panel text-primary focus:ring-primary"
                                        :checked="values.core"
                                        @change="autosave.save('core', $event.target.checked)"
                                    />
                                    {{ $t('workspace.dev.field_core') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs text-shell-muted">
                                    <input
                                        v-model="failNext"
                                        type="checkbox"
                                        class="rounded border-line bg-shell-panel text-primary focus:ring-primary"
                                    />
                                    {{ $t('workspace.dev.simulate_error') }}
                                </label>
                            </div>
                            <p v-else-if="current === 'answers'" class="text-sm text-shell-muted">
                                {{ $t('workspace.dev.answers_empty') }}
                            </p>
                            <p v-else-if="current === 'history'" class="text-sm text-shell-muted">
                                {{ $t('workspace.dev.history_empty') }}
                            </p>
                        </template>
                    </Drawer>
                </div>
            </section>

            <section class="min-w-0 space-y-3">
                <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.virtual_title') }}</h2>
                <DataTable
                    v-model:selection="manySelection"
                    :rows="manyRows"
                    :columns="manyColumns"
                    :label="$t('workspace.dev.virtual_label')"
                    :row-label="(row) => $t('workspace.dev.row_n', { n: row.id })"
                    dense
                >
                    <template #cell-name="{ row }">{{ $t('workspace.dev.row_n', { n: row.id }) }}</template>
                    <template #cell-market="{ row }">{{ $t(`workspace.dev.markets.${row.market}`) }}</template>
                    <template #cell-answers="{ value }">{{ formatNumber(value) }}</template>
                </DataTable>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="min-w-0 space-y-3">
                    <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.empty_title') }}</h2>
                    <DataTable
                        :rows="[]"
                        :columns="manyColumns"
                        :label="$t('workspace.dev.empty_label')"
                        :empty="{
                            title: $t('workspace.dev.empty_heading'),
                            body: $t('workspace.dev.empty_body'),
                            action: { label: $t('workspace.dev.empty_action'), href: '/dev/components' },
                        }"
                    />
                </section>
                <section class="min-w-0 space-y-3">
                    <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.loading_title') }}</h2>
                    <DataTable :rows="[]" :columns="manyColumns" :label="$t('workspace.dev.loading_label')" loading />
                </section>
            </div>

            <section class="min-w-0 space-y-3">
                <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.dev.status_title') }}</h2>
                <div class="flex flex-wrap items-center gap-6 rounded-[14px] border border-line p-4">
                    <SaveStatus v-for="state in statusStates" :key="state" :state="state" />
                    <button
                        type="button"
                        class="rounded-full border border-line px-3 py-1 text-sm text-shell-text hover:bg-row-hover"
                        @click="toast.show($t('workspace.dev.toast_text'), async () => {})"
                    >
                        {{ $t('workspace.dev.show_toast') }}
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
