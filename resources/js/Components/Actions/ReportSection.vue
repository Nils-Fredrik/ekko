<script setup>
defineProps({
    actions: { type: Object, required: true },
});
</script>

<template>
    <section class="space-y-4 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
        <h3 class="font-medium">{{ actions.title }}</h3>
        <div>
            <h4 class="text-sm text-shell-muted">{{ actions.open_title }}</h4>
            <p v-if="actions.open.length === 0" class="mt-2 text-sm text-shell-muted">{{ actions.empty_open }}</p>
            <ul v-else class="mt-2 space-y-3">
                <li v-for="(action, index) in actions.open" :key="`open-${index}`">
                    <p class="text-sm font-medium">{{ action.title }}</p>
                    <p class="text-xs text-shell-muted">{{ action.impact }}</p>
                    <p v-if="action.evidence" class="mt-1 text-sm">{{ action.evidence }}</p>
                </li>
            </ul>
        </div>
        <div>
            <h4 class="text-sm text-shell-muted">{{ actions.done_title }}</h4>
            <p v-if="actions.completed.length === 0" class="mt-2 text-sm text-shell-muted">{{ actions.empty_done }}</p>
            <ul v-else class="mt-2 space-y-3">
                <li v-for="(action, index) in actions.completed" :key="`done-${index}`">
                    <p class="text-sm font-medium">{{ action.title }}</p>
                    <p class="text-xs text-shell-muted">{{ action.impact }}</p>
                    <p v-if="action.evidence" class="mt-1 text-sm">{{ action.evidence }}</p>
                    <p class="mt-1 text-xs text-shell-muted">{{ actions.change_label }}: {{ action.change }}</p>
                </li>
            </ul>
        </div>
    </section>
</template>
