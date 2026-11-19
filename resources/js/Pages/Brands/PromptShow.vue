<script setup>
import AnswerText from '@/Components/Brand/AnswerText.vue';
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import TrendChart from '@/Components/Dashboard/TrendChart.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: { type: Object, required: true },
    prompt: { type: Object, required: true },
    period: { type: Object, required: true },
    series: { type: Array, required: true },
    engines: { type: Array, required: true },
    truncated: { type: Boolean, required: true },
    shown: { type: Number, required: true },
});

function when(value) {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat(document.documentElement.lang || 'nb', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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
                <PeriodFilters
                    :brand-id="brand.id"
                    route-name="brands.prompts.show"
                    :params="{ prompt: prompt.id }"
                    :days="period.days"
                />

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <h3 class="font-medium">{{ prompt.text }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">
                        {{ prompt.market }} · {{ prompt.intent }} · {{ prompt.funnel }} · {{ prompt.priority }}
                    </p>
                </section>

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <h3 class="text-sm font-medium">{{ $t('app.dashboard.trend') }}</h3>
                    <p v-if="series.length === 0" class="mt-4 text-sm text-shell-muted">
                        {{ $t('app.dashboard.no_trend') }}
                    </p>
                    <TrendChart v-else class="mt-4" :points="series" />
                </section>

                <p v-if="truncated" class="text-sm text-shell-muted">
                    {{ $t('app.library.truncated', { count: shown }) }}
                </p>
                <p v-if="engines.length === 0" class="text-sm text-shell-muted">{{ $t('app.library.no_answers') }}</p>

                <section v-for="group in engines" :key="group.engine" class="flex flex-col gap-4">
                    <h3 class="text-sm font-medium text-shell-fg">{{ group.engine }}</h3>
                    <article
                        v-for="answer in group.answers"
                        :key="answer.id"
                        class="rounded-2xl border border-line bg-shell-panel p-5"
                    >
                        <div class="mb-3 flex items-center justify-between gap-3 text-xs text-shell-muted">
                            <span>{{ when(answer.collected_at) }}</span>
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
