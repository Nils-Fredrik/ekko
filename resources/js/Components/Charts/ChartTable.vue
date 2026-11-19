<script setup>
// The table view of a chart (docs/ui-forbedring.md 9): the same numbers as text, for screen readers,
// keyboard users and anyone who wants the exact values.
defineProps({
    caption: { type: String, required: true },
    // [{ key, label, numeric? }]
    columns: { type: Array, required: true },
    // [{ key, [column.key]: formatted text }]
    rows: { type: Array, required: true },
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">{{ caption }}</caption>
            <thead class="text-xs text-shell-muted">
                <tr class="border-b border-line">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="px-2 py-1.5 font-medium"
                        :class="column.numeric ? 'text-right' : ''"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.key" class="border-b border-line-soft last:border-0">
                    <component
                        :is="index === 0 ? 'th' : 'td'"
                        v-for="(column, index) in columns"
                        :key="column.key"
                        :scope="index === 0 ? 'row' : undefined"
                        class="px-2 py-1.5 font-normal"
                        :class="column.numeric ? 'text-right tabular-nums' : ''"
                    >
                        {{ row[column.key] }}
                    </component>
                </tr>
            </tbody>
        </table>
    </div>
</template>
