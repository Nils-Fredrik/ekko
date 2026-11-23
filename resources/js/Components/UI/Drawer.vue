<script setup>
// The detail panel next to a table (docs/ux-arbeidsflate.md 4.4). From 1280 px it is a column of its
// own beside the table, without a backdrop and without trapping the focus, so the table and j/k stay
// usable. On narrower screens it is a Headless UI Dialog from the right with backdrop and focus trap.
//
// <div class="flex items-start gap-4">
//     <div class="min-w-0 flex-1"><DataTable … /></div>
//     <Drawer :open="openId !== null" :label="…" :save-state="autosave.state" @close="close">…</Drawer>
// </div>
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/20/solid';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import SaveStatus from '@/Components/UI/SaveStatus.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    label: { type: String, required: true },
    saveState: { type: String, default: 'idle' },
    saveError: { type: String, default: '' },
    // [{ id, label }]: a tab list above the content. The default slot gets { tab }.
    tabs: { type: Array, default: () => [] },
    tab: { type: String, default: '' },
});

const emit = defineEmits(['close', 'closed', 'update:tab']);

const id = useId();
const query = typeof window !== 'undefined' ? window.matchMedia('(min-width: 1280px)') : null;
const wide = ref(query?.matches ?? true);
const onChange = (event) => {
    wide.value = event.matches;
};
onMounted(() => query?.addEventListener('change', onChange));
onBeforeUnmount(() => query?.removeEventListener('change', onChange));

watch(
    () => props.open,
    (open) => {
        if (!open && wide.value) emit('closed');
    },
    { flush: 'post' },
);

const activeTab = computed(() => props.tab || props.tabs[0]?.id || '');
const tabButtons = ref([]);

function selectTab(tabId, focus = false) {
    emit('update:tab', tabId);
    if (focus) {
        const index = props.tabs.findIndex((item) => item.id === tabId);
        tabButtons.value[index]?.focus();
    }
}

function onTabKey(event, index) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (event.key === 'Home' || event.key === 'End' || step) {
        event.preventDefault();
        const count = props.tabs.length;
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? count - 1 : (index + step + count) % count;
        selectTab(props.tabs[next].id, true);
    }
}

function onAsideKey(event) {
    if (event.key === 'Escape' && !event.defaultPrevented) {
        event.preventDefault();
        emit('close');
    }
}
</script>

<template>
    <aside
        v-if="open && wide"
        data-detail-panel
        :aria-labelledby="`${id}-title`"
        class="sticky top-4 flex max-h-[calc(100vh-2rem)] min-w-0 max-w-[420px] flex-[1_1_360px] flex-col overflow-y-auto rounded-2xl border border-line bg-shell-surface p-4 text-shell-text"
        @keydown="onAsideKey"
    >
        <div class="flex items-center gap-3">
            <h2 :id="`${id}-title`" class="min-w-0 flex-1 truncate text-xs text-shell-muted">{{ label }}</h2>
            <SaveStatus :state="saveState" :error="saveError" />
            <button
                type="button"
                class="rounded-full p-1 text-shell-muted hover:bg-row-hover hover:text-shell-text"
                :aria-label="$t('workspace.drawer.close')"
                @click="emit('close')"
            >
                <XMarkIcon class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <div v-if="tabs.length" role="tablist" class="mt-3 flex gap-1 border-b border-line">
            <button
                v-for="(item, index) in tabs"
                :id="`${id}-tab-${item.id}`"
                :key="item.id"
                ref="tabButtons"
                type="button"
                role="tab"
                :aria-selected="item.id === activeTab"
                :aria-controls="`${id}-panel`"
                :tabindex="item.id === activeTab ? 0 : -1"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="
                    item.id === activeTab
                        ? 'border-primary text-shell-fg'
                        : 'border-transparent text-shell-muted hover:text-shell-text'
                "
                @click="selectTab(item.id)"
                @keydown="onTabKey($event, index)"
            >
                {{ item.label }}
            </button>
        </div>
        <div
            :id="`${id}-panel`"
            :role="tabs.length ? 'tabpanel' : undefined"
            :aria-labelledby="tabs.length ? `${id}-tab-${activeTab}` : undefined"
            class="mt-4 min-w-0"
        >
            <slot :tab="activeTab" />
        </div>
    </aside>

    <TransitionRoot v-if="!wide" :show="open" as="template" @after-leave="emit('closed')">
        <Dialog class="relative z-40" @close="emit('close')">
            <TransitionChild
                as="template"
                enter="duration-150 ease-out"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="duration-100 ease-in"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-black/40" aria-hidden="true"></div>
            </TransitionChild>
            <div class="fixed inset-0 flex justify-end">
                <TransitionChild
                    as="template"
                    enter="duration-150 ease-out"
                    enter-from="translate-x-4 opacity-0"
                    enter-to="translate-x-0 opacity-100"
                    leave="duration-100 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <DialogPanel
                        data-detail-panel
                        class="flex h-full w-full max-w-[480px] flex-col overflow-y-auto border-l border-line bg-shell-surface p-4 text-shell-text"
                    >
                        <div class="flex items-center gap-3">
                            <DialogTitle class="min-w-0 flex-1 truncate text-xs text-shell-muted">
                                {{ label }}
                            </DialogTitle>
                            <SaveStatus :state="saveState" :error="saveError" />
                            <button
                                type="button"
                                class="rounded-full p-1 text-shell-muted hover:bg-row-hover hover:text-shell-text"
                                :aria-label="$t('workspace.drawer.close')"
                                @click="emit('close')"
                            >
                                <XMarkIcon class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                        <div v-if="tabs.length" role="tablist" class="mt-3 flex gap-1 border-b border-line">
                            <button
                                v-for="(item, index) in tabs"
                                :id="`${id}-mtab-${item.id}`"
                                :key="item.id"
                                ref="tabButtons"
                                type="button"
                                role="tab"
                                :aria-selected="item.id === activeTab"
                                :aria-controls="`${id}-mpanel`"
                                :tabindex="item.id === activeTab ? 0 : -1"
                                class="-mb-px border-b-2 px-3 py-2 text-sm"
                                :class="
                                    item.id === activeTab
                                        ? 'border-primary text-shell-fg'
                                        : 'border-transparent text-shell-muted hover:text-shell-text'
                                "
                                @click="selectTab(item.id)"
                                @keydown="onTabKey($event, index)"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                        <div
                            :id="`${id}-mpanel`"
                            :role="tabs.length ? 'tabpanel' : undefined"
                            :aria-labelledby="tabs.length ? `${id}-mtab-${activeTab}` : undefined"
                            class="mt-4 min-w-0"
                        >
                            <slot :tab="activeTab" />
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
