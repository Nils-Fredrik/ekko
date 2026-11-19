<script setup>
import BrandNav from '@/Components/Brand/BrandNav.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, required: true },
    topics: { type: Array, required: true },
    markets: { type: Array, required: true },
    prompts: { type: Array, required: true },
    statuses: { type: Array, required: true },
    types: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const status = ref(props.filters.status);
const type = ref(props.filters.type);
const topic = ref(props.filters.topic);
const creating = ref(false);
const form = useForm({
    title: '',
    description: '',
    steps: '',
    type: '',
    market_id: '',
    topic_id: '',
    prompt_ids: [],
});

function apply() {
    router.get(
        route('brands.actions.index', props.brand.id),
        { status: status.value, type: type.value, topic: topic.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function changeStatus(action, value) {
    router.patch(
        route('brands.actions.update', [props.brand.id, action.id]),
        { status: value },
        { preserveScroll: true },
    );
}

function save() {
    form.transform((data) => ({
        ...data,
        type: data.type || null,
        market_id: data.market_id || null,
        topic_id: data.topic_id || null,
    })).post(route('brands.actions.store', props.brand.id));
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>
        <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
            <BrandNav :brand-id="brand.id" />
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex flex-col gap-1 text-xs text-shell-muted">
                    {{ $t('action_items.ui.filter_status') }}
                    <select
                        v-model="status"
                        class="rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text"
                        @change="apply"
                    >
                        <option value="">{{ $t('action_items.ui.all') }}</option>
                        <option v-for="item in statuses" :key="item" :value="item">
                            {{ $t(`action_items.statuses.${item}`) }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-shell-muted">
                    {{ $t('action_items.ui.filter_type') }}
                    <select
                        v-model="type"
                        class="rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text"
                        @change="apply"
                    >
                        <option value="">{{ $t('action_items.ui.all') }}</option>
                        <option v-for="item in types" :key="item" :value="item">
                            {{ $t(`action_items.types.${item}`) }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-shell-muted">
                    {{ $t('action_items.ui.filter_topic') }}
                    <select
                        v-model="topic"
                        class="rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text"
                        @change="apply"
                    >
                        <option value="">{{ $t('action_items.ui.all') }}</option>
                        <option v-for="item in topics" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </label>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full bg-primary px-4 py-2 text-sm text-white"
                    @click="creating = !creating"
                >
                    {{ $t('action_items.ui.create') }}
                </button>
            </div>

            <form
                v-if="creating"
                class="space-y-3 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                @submit.prevent="save"
            >
                <label class="block text-sm">
                    {{ $t('action_items.ui.title') }}
                    <input
                        v-model="form.title"
                        required
                        maxlength="255"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    {{ $t('action_items.ui.description') }}
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    {{ $t('action_items.ui.steps') }}
                    <textarea
                        v-model="form.steps"
                        rows="3"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                    />
                    <span class="mt-1 block text-xs text-shell-muted">{{ $t('action_items.ui.steps_help') }}</span>
                </label>
                <div class="flex flex-wrap gap-3">
                    <label class="text-sm">
                        {{ $t('action_items.ui.filter_type') }}
                        <select
                            v-model="form.type"
                            class="mt-1 block rounded-lg border border-line bg-shell-surface px-3 py-2"
                        >
                            <option value="">{{ $t('action_items.types.own') }}</option>
                            <option v-for="item in types.filter((value) => value !== 'own')" :key="item" :value="item">
                                {{ $t(`action_items.types.${item}`) }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('action_items.ui.market') }}
                        <select
                            v-model="form.market_id"
                            class="mt-1 block rounded-lg border border-line bg-shell-surface px-3 py-2"
                        >
                            <option value="">{{ $t('action_items.ui.no_market') }}</option>
                            <option v-for="item in markets" :key="item.id" :value="item.id">{{ item.label }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('action_items.ui.filter_topic') }}
                        <select
                            v-model="form.topic_id"
                            class="mt-1 block rounded-lg border border-line bg-shell-surface px-3 py-2"
                        >
                            <option value="">{{ $t('action_items.ui.all') }}</option>
                            <option v-for="item in topics" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </label>
                </div>
                <label class="block text-sm">
                    {{ $t('action_items.ui.prompts') }}
                    <select
                        v-model="form.prompt_ids"
                        multiple
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                    >
                        <option v-for="item in prompts" :key="item.id" :value="item.id">{{ item.text }}</option>
                    </select>
                </label>
                <p v-if="form.errors.title" class="text-sm text-error">{{ form.errors.title }}</p>
                <button
                    type="submit"
                    class="rounded-full bg-primary px-4 py-2 text-sm text-white"
                    :disabled="form.processing"
                >
                    {{ $t('action_items.ui.save') }}
                </button>
            </form>

            <EmptyState
                v-if="actions.length === 0"
                :title="$t('action_items.ui.empty_title')"
                :body="$t('action_items.ui.empty_body')"
            />
            <ul v-else class="space-y-3">
                <li
                    v-for="action in actions"
                    :key="action.id"
                    class="rounded-2xl border border-line bg-shell-panel p-4 text-shell-text"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <Link
                                :href="route('brands.actions.show', [brand.id, action.id])"
                                class="font-medium text-shell-text no-underline"
                            >
                                {{ action.title }}
                            </Link>
                            <p class="mt-1 text-xs text-shell-muted">
                                {{ $t(`action_items.types.${action.type}`) }}
                                · {{ $t(`action_items.impact_levels.${action.impact}`) }}
                                <template v-if="action.topic">· {{ action.topic }}</template>
                                · {{ action.market || $t('action_items.ui.no_market') }}
                            </p>
                        </div>
                        <select
                            v-if="canManage"
                            class="rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                            :value="action.status"
                            @change="changeStatus(action, $event.target.value)"
                        >
                            <option :value="action.status">{{ $t(`action_items.statuses.${action.status}`) }}</option>
                            <option v-for="target in action.targets" :key="target" :value="target">
                                {{ $t(`action_items.statuses.${target}`) }}
                            </option>
                        </select>
                        <p v-else class="text-sm text-shell-muted">
                            {{ $t(`action_items.statuses.${action.status}`) }}
                        </p>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
