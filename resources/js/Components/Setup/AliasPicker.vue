<script setup>
// «Alias av …»: a small menu with the own brand and the active competitors, with search (docs/ux-arbeidsflate.md
// 5.3). A suggested target stands first. Arrow keys move, Enter chooses, Escape closes and the focus goes back to
// the button. The menu sits in the body, so the table's scroll area does not clip it.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { filterTargets } from './entityQueue.js';

const props = defineProps({
    targets: { type: Array, required: true },
    suggested: { type: Object, default: null },
    // The button the menu opens from, to place it and to return the focus to.
    anchor: { type: Object, default: null },
});

const emit = defineEmits(['pick', 'close']);

const id = useId();
const search = ref('');
const index = ref(0);
const field = ref(null);
const style = ref({});

const shown = computed(() => filterTargets(props.targets, search.value, props.suggested));

function place() {
    const rect = props.anchor?.getBoundingClientRect();
    if (!rect) {
        return;
    }
    const width = 288;
    const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
    const below = window.innerHeight - rect.bottom > 260;
    style.value = below
        ? { top: `${rect.bottom + 4}px`, left: `${left}px`, width: `${width}px` }
        : { bottom: `${window.innerHeight - rect.top + 4}px`, left: `${left}px`, width: `${width}px` };
}

function close() {
    emit('close');
    props.anchor?.focus?.();
}

function pick(target) {
    emit('pick', target);
}

function onKey(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const count = shown.value.length;
        index.value = count === 0 ? 0 : (index.value + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const target = shown.value[index.value];
        if (target) {
            pick(target);
        }
    }
}

function onOutside(event) {
    if (!event.target.closest?.('[data-alias-picker]') && !props.anchor?.contains(event.target)) {
        emit('close');
    }
}

onMounted(() => {
    place();
    nextTick(() => field.value?.focus());
    window.addEventListener('resize', place);
    window.addEventListener('mousedown', onOutside);
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', place);
    window.removeEventListener('mousedown', onOutside);
});

const isSuggested = (target) =>
    props.suggested !== null && target.type === props.suggested.target_type && target.id === props.suggested.target_id;
</script>

<template>
    <Teleport to="body">
        <div
            data-alias-picker
            role="dialog"
            :aria-label="$t('workspace.competitors.alias.title')"
            class="fixed z-50 rounded-xl border border-line bg-shell-surface p-2 text-sm text-shell-text shadow-pop"
            :style="style"
            @keydown="onKey"
        >
            <input
                ref="field"
                v-model="search"
                type="search"
                role="combobox"
                aria-expanded="true"
                :aria-controls="`${id}-list`"
                :aria-activedescendant="shown[index] ? `${id}-${index}` : undefined"
                class="w-full rounded-lg border border-line bg-shell-panel px-3 py-1.5"
                :placeholder="$t('workspace.competitors.alias.search')"
                :aria-label="$t('workspace.competitors.alias.search')"
                @input="index = 0"
            />
            <ul :id="`${id}-list`" role="listbox" class="mt-2 max-h-60 overflow-y-auto">
                <li
                    v-for="(target, position) in shown"
                    :id="`${id}-${position}`"
                    :key="`${target.type}-${target.id}`"
                    role="option"
                    :aria-selected="position === index"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5"
                    :class="position === index ? 'bg-row-hover' : ''"
                    data-testid="alias-option"
                    @mouseenter="index = position"
                    @click="pick(target)"
                >
                    <span class="min-w-0 flex-1 truncate">
                        {{
                            target.type === 'brand'
                                ? $t('workspace.competitors.detail.own_brand', { name: target.name })
                                : target.name
                        }}
                    </span>
                    <span
                        v-if="isSuggested(target)"
                        class="rounded-full border border-medium-beige px-2 text-[11px] text-shell-muted"
                    >
                        {{ $t('workspace.competitors.alias.suggested') }}
                    </span>
                </li>
                <li v-if="shown.length === 0" class="px-2 py-1.5 text-shell-muted">
                    {{ $t('workspace.competitors.alias.none') }}
                </li>
            </ul>
        </div>
    </Teleport>
</template>
