<script setup>
// One alert type, saved a field at a time (docs/ux-arbeidsflate.md 5.7).
import SaveStatus from '@/Components/UI/SaveStatus.vue';
import { useAutosave } from '@/Composables/useAutosave';
import { formatNumber } from '@/format';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    rule: { type: Object, required: true },
    brandId: { type: Number, required: true },
    tenant: { type: String, required: true },
    can: { type: Boolean, required: true },
});

const values = reactive({
    is_active: props.rule.is_active,
    threshold: props.rule.threshold,
    channels: [...props.rule.channels],
});
const draft = ref(props.rule.threshold ?? '');
const { save, state, error } = useAutosave(() => route('brands.alert-rules.upsert', [props.brandId, props.rule.type]), {
    method: 'put',
    values,
    only: ['rules'],
});
const channels = ['inbox', 'email', 'slack'];
const shownThreshold = computed(() => (draft.value === '' || draft.value === null ? values.threshold : draft.value));
const explanation = computed(() =>
    trans(`workspace.notifications.explain.${props.rule.type}`, {
        threshold: formatNumber(shownThreshold.value),
    }),
);

watch(
    () => values.threshold,
    (value) => {
        draft.value = value ?? '';
    },
);

function toggle(channel) {
    if (!props.can || channel === 'inbox') {
        return;
    }
    const next = values.channels.includes(channel)
        ? values.channels.filter((item) => item !== channel)
        : [...values.channels, channel];
    save('channels', ['inbox', ...next.filter((item) => item !== 'inbox')]);
}
</script>

<template>
    <article class="grid gap-3 border-b border-line-soft py-4 md:grid-cols-[minmax(0,1.4fr)_auto]">
        <div>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    role="switch"
                    class="relative h-6 w-10 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
                    :class="values.is_active ? 'bg-primary-deep' : 'bg-line'"
                    :aria-checked="values.is_active"
                    :aria-label="$t(`app.alert_types.${rule.type}`)"
                    :disabled="!can"
                    @click="save('is_active', !values.is_active)"
                >
                    <span
                        class="absolute top-0.5 h-5 w-5 rounded-full bg-shell-surface"
                        :class="values.is_active ? 'start-4' : 'start-0.5'"
                    />
                </button>
                <h3 class="text-sm text-shell-fg">{{ $t(`app.alert_types.${rule.type}`) }}</h3>
                <SaveStatus :state="state" :error="error" />
            </div>
            <p class="mt-2 text-sm text-shell-muted">{{ explanation }}</p>
            <p v-if="rule.tenant_wide" class="mt-1 text-xs text-shell-muted">
                {{ $t('workspace.notifications.tenant_wide', { tenant }) }}
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label v-if="rule.unit" class="text-xs text-shell-muted">
                {{ $t('workspace.notifications.threshold') }}
                <span class="mt-1 flex items-center gap-2">
                    <input
                        :value="draft"
                        inputmode="decimal"
                        class="w-20 rounded-lg border-line bg-shell-surface text-sm text-shell-fg disabled:opacity-50"
                        :disabled="!can"
                        :aria-label="$t('workspace.notifications.threshold')"
                        @input="draft = $event.target.value"
                        @blur="save('threshold', draft === '' ? null : draft)"
                        @keydown.enter.prevent="$event.target.blur()"
                    />
                    <span>{{ $t(`workspace.notifications.units.${rule.unit}`) }}</span>
                </span>
            </label>
            <fieldset class="flex gap-3">
                <legend class="sr-only">{{ $t('workspace.notifications.channels') }}</legend>
                <label
                    v-for="channel in channels"
                    :key="channel"
                    class="flex items-center gap-1.5 text-sm text-shell-text"
                >
                    <input
                        type="checkbox"
                        class="rounded border-line"
                        :checked="values.channels.includes(channel)"
                        :disabled="!can || channel === 'inbox'"
                        @change="toggle(channel)"
                    />
                    {{ $t(`workspace.notifications.channel.${channel}`) }}
                </label>
            </fieldset>
        </div>
    </article>
</template>
