<script setup>
import AnswerText from '@/Components/Brand/AnswerText.vue';
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import { periodBuckets } from '@/Components/Charts/support/data.js';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    prompt: { type: Object, required: true },
    period: { type: Object, required: true },
    engines: { type: Array, required: true },
    truncated: { type: Boolean, required: true },
    shown: { type: Number, required: true },
});

const selected = ref(null);

const timelines = computed(() =>
    props.engines.map((group) => {
        const byDate = new Map();
        for (const answer of group.answers) {
            if (!answer.date) {
                continue;
            }
            if (!byDate.has(answer.date)) {
                byDate.set(answer.date, []);
            }
            byDate.get(answer.date).push(answer);
        }

        return {
            ...group,
            days: periodBuckets(props.period.from, props.period.to).map((date) => {
                const answers = byDate.get(date) ?? [];

                return { date, answers, mentioned: answers.some((answer) => answer.mentioned) };
            }),
        };
    }),
);

function latestDay(group) {
    return [...group.days].reverse().find((day) => day.answers.length) ?? null;
}

watch(
    timelines,
    (groups) => {
        const current = groups.find((group) => group.key === selected.value?.key);
        const stillThere = current?.days.some((day) => day.date === selected.value?.date && day.answers.length);
        if (stillThere) {
            return;
        }
        const group = groups[0];
        const day = group ? latestDay(group) : null;
        selected.value = group && day ? { key: group.key, date: day.date } : null;
    },
    { immediate: true },
);

function when(value) {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat(document.documentElement.lang || 'nb', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function changeKey(change) {
    if (change === 'gained' || change === 'lost' || change === 'same') {
        return `app.library.mention_${change}`;
    }

    return '';
}
</script>

<template>
    <AppLayout :title="prompt.text">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <BrandNav :brand-id="brand.id" />
                <PeriodFilters :brand-id="brand.id" route-name="brands.prompts.show" :params="{ prompt: prompt.id }" />

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <h3 class="font-medium">{{ prompt.text }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">
                        {{ prompt.market }} · {{ prompt.intent }} · {{ prompt.funnel }} · {{ prompt.priority }}
                    </p>
                </section>

                <p v-if="truncated" class="text-sm text-shell-muted">
                    {{ $t('app.library.truncated', { count: shown }) }}
                </p>
                <EmptyState
                    v-if="engines.length === 0"
                    :title="$t('app.empty.answers_title')"
                    :body="$t('app.empty.answers_body')"
                />

                <section
                    v-for="group in timelines"
                    :key="group.key"
                    class="grid items-start gap-4 lg:grid-cols-[minmax(16rem,1fr)_minmax(0,1.4fr)]"
                >
                    <div>
                        <h3 class="text-sm font-medium text-shell-fg">{{ group.engine }}</h3>
                        <div class="mt-3 flex flex-wrap gap-1" role="list">
                            <button
                                v-for="day in group.days"
                                :key="day.date"
                                type="button"
                                class="size-4 rounded-full border focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                :class="
                                    day.mentioned
                                        ? 'border-primary bg-primary'
                                        : day.answers.length
                                          ? 'border-line bg-shell-surface'
                                          : 'border-line'
                                "
                                :disabled="day.answers.length === 0"
                                :aria-pressed="
                                    selected?.key === group.key && selected?.date === day.date ? 'true' : 'false'
                                "
                                :aria-label="day.date"
                                @click="selected = { key: group.key, date: day.date }"
                            />
                        </div>
                    </div>
                    <div v-if="selected?.key === group.key" class="flex flex-col gap-4">
                        <article
                            v-for="answer in group.days.find((day) => day.date === selected.date)?.answers ?? []"
                            :key="answer.id"
                            class="rounded-2xl border border-line bg-shell-panel p-5"
                        >
                            <div
                                class="mb-3 flex flex-wrap items-center justify-between gap-3 text-xs text-shell-muted"
                            >
                                <span>{{ when(answer.collected_at) }}</span>
                                <span v-if="changeKey(answer.change)">{{ $t(changeKey(answer.change)) }}</span>
                                <a :href="answer.raw" class="text-primary no-underline">{{ $t('app.library.raw') }}</a>
                            </div>
                            <AnswerText :segments="answer.segments" />
                            <ul v-if="answer.citations.length" class="mt-3 flex flex-col gap-1 text-xs">
                                <li v-for="citation in answer.citations" :key="citation.url || citation.domain">
                                    <a v-if="citation.url" :href="citation.url" class="text-shell-text no-underline">
                                        {{ citation.domain }}
                                    </a>
                                    <span v-else>{{ citation.domain }}</span>
                                    <span class="text-shell-muted">· {{ citation.ownership }}</span>
                                </li>
                            </ul>
                        </article>
                    </div>
                </section>

                <p class="flex flex-wrap gap-3 text-xs text-shell-muted">
                    <span class="rounded-sm bg-primary/15 px-1">{{ $t('app.library.mention_brand') }}</span>
                    <span class="rounded-sm bg-medium-beige/60 px-1">{{ $t('app.library.mention_competitor') }}</span>
                    <span class="underline decoration-warning decoration-2">
                        {{ $t('app.library.mention_unknown') }}
                    </span>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
