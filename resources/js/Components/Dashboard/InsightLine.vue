<script setup>
// The insight line (U12, ADR 0038). `insight` is the deferred Inertia prop of the page: null (nothing is shown, no
// error), or { text, label, generated_on }. The text is written by a model with the figures filled in from the
// database, so it always shows the same numbers as the page. Wrap the page's prop in <Deferred data="insight"> or pass
// it straight in; the component renders nothing until there is a text.
defineProps({
    insight: { type: Object, default: null },
});
</script>

<template>
    <p v-if="insight?.text" class="text-sm text-shell-text" data-testid="insight-line">
        <span class="me-2 rounded-full border border-line px-2 py-0.5 text-xs text-shell-muted">
            {{ insight.label }}
        </span>
        <span>{{ insight.text }}</span>
    </p>
</template>
