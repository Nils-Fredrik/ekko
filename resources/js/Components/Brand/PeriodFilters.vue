<script setup>
import BottomSheet from '@/Components/UI/BottomSheet.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    brandId: { type: Number, required: true },
    routeName: { type: String, required: true },
    days: { type: Number, required: true },
    markets: { type: Array, default: () => [] },
    market: { type: [Number, String], default: null },
    engines: { type: Array, default: () => [] },
    engine: { type: String, default: '' },
    params: { type: Object, default: () => ({}) },
});

const daysModel = ref(props.days);
const marketModel = ref(props.market ?? '');
const engineModel = ref(props.engine ?? '');
const open = ref(false);
const lengths = [7, 28, 90];

function apply() {
    const params = {};
    if (daysModel.value) params.days = daysModel.value;
    if (marketModel.value) params.market = marketModel.value;
    if (engineModel.value) params.engine = engineModel.value;
    open.value = false;
    router.get(route(props.routeName, { brand: props.brandId, ...props.params }), params, {
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <div>
        <div class="hidden flex-wrap items-end gap-3 sm:flex">
            <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                {{ $t('app.dashboard.period') }}
                <select
                    v-model="daysModel"
                    class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                    @change="apply"
                >
                    <option v-for="length in lengths" :key="length" :value="length">
                        {{ $t('app.dashboard.period_days', { days: length }) }}
                    </option>
                </select>
            </label>
            <label v-if="markets.length" class="flex flex-col gap-1 text-xs text-shell-text/70">
                {{ $t('app.dashboard.market') }}
                <select
                    v-model="marketModel"
                    class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                    @change="apply"
                >
                    <option value="">{{ $t('app.dashboard.all_markets') }}</option>
                    <option v-for="item in markets" :key="item.id" :value="item.id">{{ item.label }}</option>
                </select>
            </label>
            <label v-if="engines.length" class="flex flex-col gap-1 text-xs text-shell-text/70">
                {{ $t('app.dashboard.engine') }}
                <select
                    v-model="engineModel"
                    class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                    @change="apply"
                >
                    <option value="">{{ $t('app.dashboard.all_engines') }}</option>
                    <option v-for="item in engines" :key="item.key" :value="item.key">{{ item.name }}</option>
                </select>
            </label>
        </div>

        <div class="sm:hidden">
            <button type="button" class="rounded-full border border-line px-4 py-2 text-sm" @click="open = true">
                {{ $t('app.dashboard.open_filters') }}
            </button>
            <BottomSheet :show="open" :title="$t('app.dashboard.filters')" @close="open = false">
                <div class="flex flex-col gap-4 p-4">
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.period') }}
                        <select
                            v-model="daysModel"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                        >
                            <option v-for="length in lengths" :key="length" :value="length">
                                {{ $t('app.dashboard.period_days', { days: length }) }}
                            </option>
                        </select>
                    </label>
                    <label v-if="markets.length" class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.market') }}
                        <select
                            v-model="marketModel"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                        >
                            <option value="">{{ $t('app.dashboard.all_markets') }}</option>
                            <option v-for="item in markets" :key="item.id" :value="item.id">{{ item.label }}</option>
                        </select>
                    </label>
                    <label v-if="engines.length" class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.engine') }}
                        <select
                            v-model="engineModel"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                        >
                            <option value="">{{ $t('app.dashboard.all_engines') }}</option>
                            <option v-for="item in engines" :key="item.key" :value="item.key">{{ item.name }}</option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="rounded-full bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-deep"
                        @click="apply"
                    >
                        {{ $t('app.dashboard.apply') }}
                    </button>
                </div>
            </BottomSheet>
        </div>
    </div>
</template>
