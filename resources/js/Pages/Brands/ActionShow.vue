<script setup>
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    brand: { type: Object, required: true },
    action: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
});

function changeStatus(value) {
    router.patch(
        route('brands.actions.update', [props.brand.id, props.action.id]),
        { status: value },
        { preserveScroll: true },
    );
}

function toggle(index, done) {
    router.patch(
        route('brands.actions.steps', [props.brand.id, props.action.id, index]),
        { done },
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout :title="action.title">
        <template #header>
            <WorkspaceHeader :brand="brand.name" :group="$t('workspace.shell.work')" :title="action.title" />
        </template>
        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8">
            <Link :href="route('brands.actions.index', brand.id)" class="text-sm text-primary no-underline">
                {{ $t('action_items.ui.back') }}
            </Link>
            <article class="space-y-4 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
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
                        :aria-label="$t('app.dashboard.status')"
                        :value="action.status"
                        @change="changeStatus($event.target.value)"
                    >
                        <option :value="action.status">{{ $t(`action_items.statuses.${action.status}`) }}</option>
                        <option v-for="target in action.targets" :key="target" :value="target">
                            {{ $t(`action_items.statuses.${target}`) }}
                        </option>
                    </select>
                    <p v-else class="text-sm">{{ $t(`action_items.statuses.${action.status}`) }}</p>
                </div>
                <p v-if="action.description" class="text-sm">{{ action.description }}</p>
                <section v-if="action.evidence || action.figures.length">
                    <h4 class="text-sm font-medium">{{ $t('action_items.ui.evidence') }}</h4>
                    <p v-if="action.evidence" class="mt-1 text-sm">{{ action.evidence }}</p>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div v-for="figure in action.figures" :key="figure.label" class="flex gap-2">
                            <dt class="text-shell-muted">{{ figure.label }}</dt>
                            <dd>{{ figure.value }}</dd>
                        </div>
                    </dl>
                </section>
                <section v-if="action.competitors.length">
                    <h4 class="text-sm font-medium">{{ $t('action_items.ui.competitors') }}</h4>
                    <ul class="mt-1 list-disc ps-5 text-sm">
                        <li v-for="line in action.competitors" :key="line">{{ line }}</li>
                    </ul>
                </section>
                <section v-if="action.prompts.length">
                    <h4 class="text-sm font-medium">{{ $t('action_items.ui.prompts') }}</h4>
                    <ul class="mt-1 space-y-1 text-sm">
                        <li v-for="prompt in action.prompts" :key="prompt.id">
                            <Link
                                :href="route('brands.prompts.show', [brand.id, prompt.id])"
                                class="text-primary no-underline"
                            >
                                {{ prompt.text }}
                            </Link>
                        </li>
                    </ul>
                </section>
                <section v-if="action.engines.length">
                    <h4 class="text-sm font-medium">{{ $t('action_items.ui.engines') }}</h4>
                    <p class="mt-1 text-sm">{{ action.engines.join(', ') }}</p>
                </section>
                <section v-if="action.steps.length">
                    <h4 class="text-sm font-medium">{{ $t('action_items.ui.steps') }}</h4>
                    <ul class="mt-2 space-y-2">
                        <li v-for="(step, index) in action.steps" :key="index" class="flex items-start gap-2 text-sm">
                            <input
                                v-if="canManage"
                                type="checkbox"
                                :checked="step.done"
                                :aria-label="step.text"
                                @change="toggle(index, $event.target.checked)"
                            />
                            <span :class="step.done ? 'text-shell-muted line-through' : ''">{{ step.text }}</span>
                        </li>
                    </ul>
                </section>
            </article>
        </div>
    </AppLayout>
</template>
