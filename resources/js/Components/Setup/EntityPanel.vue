<script setup>
// The detail panel of an unknown name (docs/ux-arbeidsflate.md 5.3, sketch 3): the name, how often it is found,
// three excerpts from the newest answers with the name highlighted, and a list to add it as an alias.
import { formatCount, formatDate, formatPercent } from '@/format';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { aliasTargets } from './entityQueue.js';

const props = defineProps({
    brandId: { type: Number, required: true },
    item: { type: Object, required: true },
    decided: { type: Object, default: null },
    markets: { type: Array, required: true },
    engineNames: { type: Object, required: true },
    targets: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    canManageBrand: { type: Boolean, required: true },
});

const emit = defineEmits(['alias']);

// The excerpts of names already opened, so j and k back and forth do not ask again.
const cache = new Map();
const excerpts = ref(null);
const failed = ref(false);
const choice = ref('');
let request = 0;

const marketLabel = (id) => props.markets.find((market) => market.id === id)?.label ?? '';
const marketNames = computed(() => props.item.markets.map(marketLabel).filter(Boolean).join(' · '));
const options = computed(() => aliasTargets(props.targets, props.canManageBrand));
const variants = computed(() => props.item.variants.filter((variant) => variant !== props.item.name));

async function load(key) {
    const mine = ++request;
    failed.value = false;
    if (cache.has(key)) {
        excerpts.value = cache.get(key);

        return;
    }
    excerpts.value = null;
    try {
        const { data } = await window.axios.get(route('brands.entities.excerpts', [props.brandId, key]));
        cache.set(key, data.excerpts);
        if (mine === request) {
            excerpts.value = data.excerpts;
        }
    } catch {
        if (mine === request) {
            failed.value = true;
            excerpts.value = [];
        }
    }
}

watch(
    () => props.item.key,
    (key) => {
        choice.value = '';
        load(key);
    },
    { immediate: true },
);

function pickAlias(event) {
    const value = event.target.value;
    event.target.value = '';
    choice.value = '';
    const target = options.value.find((candidate) => `${candidate.type}:${candidate.id}` === value);
    if (target) {
        emit('alias', target);
    }
}

function source(excerpt) {
    return trans('workspace.competitors.detail.source', {
        engine: props.engineNames[excerpt.engine] ?? excerpt.engine,
        market: excerpt.market.label,
        date: formatDate(excerpt.collected_at),
    });
}
</script>

<template>
    <div data-testid="entity-panel">
        <h3 class="text-[20px] leading-tight font-medium text-shell-fg" data-testid="entity-panel-name">
            {{ item.name }}
        </h3>
        <p class="mt-1 text-sm text-shell-muted">
            {{
                trans('workspace.competitors.detail.summary', {
                    answers: formatCount(item.answers, 'answers'),
                    percent: formatPercent(item.share),
                })
            }}
            <template v-if="marketNames">· {{ marketNames }}</template>
            <template v-if="variants.length">
                · {{ trans('workspace.competitors.detail.also_written', { variants: variants.join(', ') }) }}
            </template>
        </p>

        <h4 class="mt-5 text-xs text-shell-muted">{{ $t('workspace.competitors.detail.excerpts') }}</h4>
        <p v-if="excerpts === null" class="mt-2 text-sm text-shell-muted">
            {{ $t('workspace.competitors.detail.excerpts_loading') }}
        </p>
        <p v-else-if="failed" class="mt-2 text-sm text-error-text">
            {{ $t('workspace.competitors.detail.excerpts_failed') }}
        </p>
        <p v-else-if="excerpts.length === 0" class="mt-2 text-sm text-shell-muted">
            {{ $t('workspace.competitors.detail.excerpts_empty') }}
        </p>
        <div v-else class="mt-2 space-y-2" data-testid="entity-excerpts">
            <figure
                v-for="(excerpt, index) in excerpts"
                :key="index"
                class="rounded-xl border border-line bg-shell-panel p-3"
            >
                <blockquote class="text-sm leading-relaxed text-shell-text">
                    <span>{{ excerpt.before }}</span>
                    <mark class="rounded bg-[var(--data-seq-2)] px-1 text-shell-fg">{{ excerpt.match }}</mark>
                    <span>{{ excerpt.after }}</span>
                </blockquote>
                <figcaption class="mt-1 text-xs text-shell-muted">{{ source(excerpt) }}</figcaption>
            </figure>
        </div>

        <label v-if="canManage && !decided" class="mt-5 block text-xs text-shell-muted">
            {{ $t('workspace.competitors.detail.alias_label') }}
            <select
                :value="choice"
                class="mt-1 w-full rounded-xl border border-line bg-shell-panel px-3 py-2 text-sm text-shell-fg"
                data-testid="entity-alias-select"
                @change="pickAlias"
            >
                <option value="">{{ $t('workspace.competitors.detail.alias_placeholder') }}</option>
                <option
                    v-for="target in options"
                    :key="`${target.type}-${target.id}`"
                    :value="`${target.type}:${target.id}`"
                >
                    {{
                        target.type === 'brand'
                            ? $t('workspace.competitors.detail.own_brand', { name: target.name })
                            : target.name
                    }}
                </option>
            </select>
        </label>
        <p v-if="decided" class="mt-5 text-sm text-success-text">
            {{ $t('workspace.competitors.detail.decided', { what: decided }) }}
        </p>
        <p class="mt-4 text-xs text-shell-muted">{{ $t('workspace.competitors.detail.explain') }}</p>
    </div>
</template>
