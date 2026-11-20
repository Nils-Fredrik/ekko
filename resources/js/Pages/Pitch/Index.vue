<script setup>
import PitchCreateForm from '@/Components/PitchCreateForm.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

defineProps({
    pitches: { type: Array, required: true },
    limit: { type: Number, required: true },
    active: { type: Number, required: true },
});

const busy = ref(null);
const statusTone = {
    active: 'bg-primary/15 text-primary',
    expired: 'bg-shell-surface text-shell-text',
    converted: 'bg-success/15 text-success',
};

function date(value) {
    return value
        ? new Intl.DateTimeFormat(document.documentElement.lang || 'nb', { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : '';
}

function open(pitch) {
    busy.value = pitch.id;
    router.post(route('pitches.open', pitch.id), {}, { onFinish: () => (busy.value = null) });
}

function convert(pitch) {
    if (!window.confirm(trans('pitch.ui.confirm_convert', { name: pitch.name }))) {
        return;
    }
    busy.value = pitch.id;
    router.post(
        route('pitches.convert', pitch.id),
        {},
        { preserveScroll: true, errorBag: 'convert', onFinish: () => (busy.value = null) },
    );
}
</script>

<template>
    <AppLayout :title="$t('pitch.ui.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('pitch.ui.title') }}</h2>
        </template>

        <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="max-w-3xl text-sm text-shell-muted">{{ $t('pitch.ui.intro') }}</p>
                <span class="text-xs text-shell-muted tabular-nums">
                    {{ $t('pitch.ui.active_count', { count: active, max: limit }) }}
                </span>
            </div>

            <p
                v-if="$page.props.errors.convert?.pitch"
                role="alert"
                class="rounded-lg border border-error/40 px-4 py-3 text-sm text-error"
            >
                {{ $page.props.errors.convert.pitch }}
            </p>

            <PitchCreateForm />

            <EmptyState
                v-if="pitches.length === 0"
                :title="$t('pitch.ui.empty_title')"
                :body="$t('pitch.ui.empty_body')"
            />
            <ul v-else class="grid gap-4 md:grid-cols-2" data-testid="pitches">
                <li
                    v-for="pitch in pitches"
                    :key="pitch.id"
                    class="flex flex-col gap-3 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                    :data-pitch="pitch.name"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-medium text-shell-fg">{{ pitch.name }}</h3>
                            <p v-if="pitch.brand" class="text-sm text-shell-muted">{{ pitch.brand.name }}</p>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusTone[pitch.status]">
                            {{ $t(`pitch.ui.status.${pitch.status}`) }}
                        </span>
                    </div>

                    <div v-if="pitch.status === 'active'" class="flex flex-col gap-1">
                        <div class="flex justify-between text-xs text-shell-muted">
                            <span>{{ $t('pitch.ui.day', { day: pitch.day, days: pitch.days }) }}</span>
                            <span>{{ $t('pitch.ui.expires', { date: date(pitch.expires_at) }) }}</span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-shell-surface"
                            role="progressbar"
                            :aria-valuenow="pitch.day"
                            aria-valuemin="0"
                            :aria-valuemax="pitch.days"
                            :aria-label="$t('pitch.ui.day', { day: pitch.day, days: pitch.days })"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{ width: `${(pitch.day / pitch.days) * 100}%` }"
                            />
                        </div>
                    </div>
                    <p v-else-if="pitch.status === 'converted'" class="text-xs text-shell-muted">
                        {{ $t('pitch.ui.converted_at', { date: date(pitch.converted_at) }) }}
                    </p>
                    <p v-else class="text-xs text-shell-muted">
                        {{ $t('pitch.ui.ended', { date: date(pitch.expires_at) }) }}
                    </p>

                    <p v-if="!pitch.brand" class="text-sm text-shell-muted">{{ $t('pitch.ui.not_set_up') }}</p>
                    <template v-else>
                        <p class="text-sm tabular-nums">
                            {{ $t('pitch.ui.progress', { prompts: pitch.prompts, answers: pitch.answers }) }}
                        </p>
                        <p class="text-xs text-shell-muted">
                            {{
                                pitch.last_answer_at
                                    ? $t('pitch.ui.last_answer', { date: date(pitch.last_answer_at) })
                                    : $t('pitch.ui.no_answers')
                            }}
                        </p>
                    </template>

                    <div class="mt-auto flex flex-wrap items-center gap-4 pt-2">
                        <button
                            v-if="pitch.brand || pitch.status === 'active'"
                            type="button"
                            class="rounded-full bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-deep disabled:opacity-40"
                            :disabled="busy === pitch.id"
                            @click="open(pitch)"
                        >
                            {{ pitch.brand ? $t('pitch.ui.open_report') : $t('pitch.ui.set_up') }}
                        </button>
                        <button
                            v-if="pitch.status !== 'converted'"
                            type="button"
                            class="text-sm text-primary disabled:opacity-40"
                            :disabled="busy === pitch.id"
                            @click="convert(pitch)"
                        >
                            {{ $t('pitch.convert') }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
