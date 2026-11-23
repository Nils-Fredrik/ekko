<script setup>
import { formatNumber } from '@/format';
import axios from 'axios';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    brandId: { type: Number, required: true },
    entity: { type: String, required: true },
    alias: { type: String, required: true },
    mode: { type: String, required: true },
});

const preview = ref(null);
const previewError = ref('');
let timer;
let request;

function cancel() {
    window.clearTimeout(timer);
    request?.abort();
}

function schedule(delay) {
    cancel();
    preview.value = null;
    previewError.value = '';
    const alias = props.alias.trim();
    if (!alias) return;
    const controller = new AbortController();
    request = controller;
    const payload = { entity: props.entity, alias, match_mode: props.mode };
    const url = route('brands.aliases.test', props.brandId);
    timer = window.setTimeout(async () => {
        try {
            const { data } = await axios.post(url, payload, { signal: controller.signal });
            if (!controller.signal.aborted) preview.value = data;
        } catch (error) {
            if (controller.signal.aborted) return;
            const messages = error.response?.data?.errors?.alias;
            previewError.value = (Array.isArray(messages) ? messages[0] : null) || error.response?.data?.message || '';
        }
    }, delay);
}

watch(
    () => [props.brandId, props.entity, props.alias, props.mode],
    () => schedule(500),
    { immediate: true },
);
onBeforeUnmount(cancel);
defineExpose({ refresh: () => schedule(0) });
</script>

<template>
    <p v-if="preview" data-testid="alias-preview" class="text-xs">
        {{
            $t('workspace.setup.match_result', {
                hits: formatNumber(preview.matches),
                answers: formatNumber(preview.scanned),
            })
        }}
    </p>
    <p v-for="(sample, index) in preview?.samples ?? []" :key="index" class="text-xs text-shell-muted">
        {{ sample.excerpt.before }}
        <mark class="rounded bg-[var(--data-seq-2)] px-1 text-shell-fg">{{ sample.excerpt.match }}</mark>
        {{ sample.excerpt.after }}
    </p>
    <p
        v-for="conflict in preview?.conflicts ?? []"
        :key="`${conflict.type}-${conflict.name}`"
        class="text-xs text-warning"
    >
        {{ $t('workspace.setup.conflict', { count: formatNumber(conflict.count), name: conflict.name }) }}
    </p>
    <p v-if="previewError" class="text-xs text-warning">{{ previewError }}</p>
</template>
