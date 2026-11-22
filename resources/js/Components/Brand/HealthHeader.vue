<script setup>
// The three cards above the figures (docs/ux-arbeidsflate.md 5.1 and 5.9, U26).
import { CheckIcon } from '@heroicons/vue/20/solid';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { formatNumber, formatRelative, formatTime } from '@/format';

const props = defineProps({
    health: { type: Object, required: true },
    brandId: { type: Number, required: true },
    canCreateMarket: { type: Boolean, default: false },
});

const planUrl = computed(() => route('brands.setup.markets', props.brandId));
const required = computed(() => (props.health.checklist ?? []).filter((step) => !step.optional));
const complete = computed(() => required.value.length > 0 && required.value.every((step) => step.done));
const showChecklist = computed(() => !props.health.checklist_hidden && !complete.value);
const doneCount = computed(() => (props.health.checklist ?? []).filter((step) => step.done).length);
const edges = {
    warning: 'bg-warning',
    error: 'bg-error',
};

function measured(market) {
    if (!market.last_completed_at) {
        return trans('workspace.health.not_measured');
    }

    return trans('workspace.health.measured', {
        when: formatRelative(market.last_completed_at),
        time: formatTime(market.last_completed_at),
    });
}

function nextRun(market) {
    return market.next_due_on ? trans('workspace.health.next', { when: formatRelative(market.next_due_on) }) : '';
}

function hideChecklist() {
    router.post(
        route('brands.checklist.hide', props.brandId),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['health'],
        },
    );
}
</script>

<template>
    <section v-if="health.markets.length === 0" data-testid="health-header">
        <Link
            v-if="canCreateMarket"
            :href="route('brands.markets.create', brandId)"
            class="text-sm text-shell-accent no-underline"
        >
            {{ $t('workspace.health.add_market') }}
        </Link>
        <p v-else class="text-sm text-shell-text">{{ $t('workspace.health.add_market') }}</p>
    </section>
    <section
        v-else
        class="grid gap-3"
        :class="showChecklist ? '' : 'md:grid-cols-2'"
        :style="showChecklist ? { gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' } : undefined"
        data-testid="health-header"
    >
        <article class="rounded-[14px] border border-line-soft bg-shell-surface p-3">
            <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.health.measurement') }}</h2>
            <ul class="mt-2 space-y-1 text-xs leading-4 text-shell-text">
                <li v-for="market in health.markets" :key="market.id">
                    <span class="text-shell-fg">{{ market.label }}</span>
                    {{ measured(market) }}
                    <template v-if="nextRun(market)">· {{ nextRun(market) }}</template>
                </li>
            </ul>
            <p class="mt-2 text-xs leading-4 text-shell-muted">
                <Link v-if="health.engines_active === 0" :href="planUrl" class="text-shell-accent no-underline">
                    {{ $t('workspace.health.no_engines') }}
                </Link>
                <template v-else>
                    {{
                        $t('workspace.health.engines', {
                            engines: formatNumber(health.engines_active),
                            answers: formatNumber(health.answers_per_month),
                        })
                    }}
                </template>
            </p>
            <Link :href="planUrl" class="mt-2 inline-block text-xs text-shell-accent no-underline">
                {{ $t('workspace.health.open_plan') }}
            </Link>
        </article>

        <article class="rounded-[14px] border border-line-soft bg-shell-surface p-3">
            <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.health.attention') }}</h2>
            <p v-if="health.problems.length === 0" class="mt-2 text-sm text-success">
                {{ $t('workspace.health.all_clear') }}
            </p>
            <ul v-else class="mt-2 space-y-1.5">
                <li v-for="problem in health.problems" :key="problem.kind">
                    <Link
                        :href="problem.url"
                        class="flex items-start gap-2 text-xs leading-4 text-shell-text no-underline"
                    >
                        <span
                            class="mt-0.5 h-4 w-1 shrink-0 rounded"
                            :class="edges[problem.severity] || 'bg-warning'"
                            aria-hidden="true"
                        />
                        <span>{{ problem.text }}</span>
                    </Link>
                </li>
            </ul>
        </article>

        <article v-if="showChecklist" class="rounded-[14px] border border-line-soft bg-shell-surface p-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.health.checklist') }}</h2>
                <button
                    type="button"
                    class="text-xs text-shell-muted underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    data-testid="hide-checklist"
                    @click="hideChecklist"
                >
                    {{ $t('workspace.health.hide') }}
                </button>
            </div>
            <p class="mt-1 text-xs text-shell-muted">
                {{
                    $t('workspace.health.progress', {
                        done: formatNumber(doneCount),
                        total: formatNumber(health.checklist.length),
                    })
                }}
            </p>
            <div class="mt-1.5 h-1 overflow-hidden rounded bg-line-soft" aria-hidden="true">
                <div
                    class="h-full bg-success"
                    :style="{ width: `${health.checklist.length ? (doneCount / health.checklist.length) * 100 : 0}%` }"
                />
            </div>
            <ul class="mt-2 space-y-1">
                <li v-for="step in health.checklist" :key="step.key" class="flex items-start gap-2 text-xs leading-4">
                    <CheckIcon v-if="step.done" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" aria-hidden="true" />
                    <span
                        v-else
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded-full border border-line"
                        aria-hidden="true"
                    />
                    <span>
                        <Link
                            :href="step.url"
                            class="text-shell-text no-underline"
                            :class="step.done ? 'text-shell-muted' : ''"
                        >
                            {{ step.text }}
                        </Link>
                        <span v-if="step.optional" class="text-shell-muted">{{ $t('workspace.health.optional') }}</span>
                    </span>
                </li>
            </ul>
        </article>
    </section>
</template>
