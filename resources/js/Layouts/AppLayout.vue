<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import ActingAsBanner from '@/Components/ActingAsBanner.vue';
import Banner from '@/Components/Banner.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import UndoToast from '@/Components/UI/UndoToast.vue';
import PageSkeleton from '@/Components/UI/PageSkeleton.vue';
import WorkspaceSidebar from '@/Components/Workspace/WorkspaceSidebar.vue';

defineProps({
    title: String,
});

// The workspace shell (docs/ux-arbeidsflate.md 4.1): the sidebar on the left, and below 768 px a top
// strip with a menu button that opens the sidebar as a drawer.
const drawerOpen = ref(false);
const palette = ref(null);
const navigating = ref(false);
let stopNavigationStart;
let stopNavigationFinish;
let stopNavigate;

function openPalette() {
    drawerOpen.value = false;
    palette.value?.show();
}

onMounted(() => {
    stopNavigationStart = router.on('start', (event) => {
        const visit = event.detail.visit;
        if (String(visit.method).toLowerCase() !== 'get') {
            return;
        }
        const next = new URL(String(visit.url), window.location.origin);
        if (next.pathname !== window.location.pathname) {
            navigating.value = true;
        }
    });
    stopNavigationFinish = router.on('finish', () => {
        navigating.value = false;
    });
    // The drawer closes when a link in it leads somewhere.
    stopNavigate = router.on('navigate', () => {
        drawerOpen.value = false;
    });
});

onBeforeUnmount(() => {
    stopNavigationStart?.();
    stopNavigationFinish?.();
    stopNavigate?.();
});
</script>

<template>
    <div>
        <Head :title="title" />

        <ActingAsBanner />

        <Banner />

        <div class="min-h-screen bg-shell text-shell-text md:flex">
            <aside class="hidden w-[248px] flex-none border-e border-line-soft md:block">
                <div class="sticky top-0 h-screen overflow-y-auto">
                    <WorkspaceSidebar @search="openPalette" />
                </div>
            </aside>

            <div
                class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line-soft bg-shell/90 px-4 backdrop-blur md:hidden"
            >
                <Link :href="route('dashboard')" class="flex flex-none items-center no-underline">
                    <span class="flex size-7 items-center justify-center rounded-lg bg-shell-panel">
                        <img src="/images/maksimer-m-square.svg" alt="" class="logo-mark size-[17px]" />
                    </span>
                    <span class="sr-only">{{ $t('app.name') }}</span>
                </Link>
                <span class="min-w-0 flex-1 truncate text-[15px] font-medium text-shell-fg">
                    {{ title || $t('app.name') }}
                </span>
                <button
                    type="button"
                    class="inline-flex size-11 flex-none items-center justify-center rounded-lg text-shell-muted hover:bg-row-hover hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-label="$t('app.nav.open_menu')"
                    :aria-expanded="drawerOpen"
                    data-testid="open-menu"
                    @click="drawerOpen = true"
                >
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
            </div>

            <TransitionRoot :show="drawerOpen" as="template">
                <Dialog class="relative z-50 md:hidden" @close="drawerOpen = false">
                    <TransitionChild
                        as="template"
                        enter="transition-opacity duration-200"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="transition-opacity duration-150"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <div class="fixed inset-0 bg-black/60" aria-hidden="true"></div>
                    </TransitionChild>
                    <TransitionChild
                        as="template"
                        enter="transition-transform duration-200 ease-out"
                        enter-from="-translate-x-full"
                        enter-to="translate-x-0"
                        leave="transition-transform duration-150 ease-in"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-full"
                    >
                        <DialogPanel
                            class="fixed inset-y-0 start-0 flex w-[min(300px,88vw)] flex-col overflow-y-auto border-e border-line-soft bg-shell text-shell-text"
                            :aria-label="$t('workspace.shell.main_nav')"
                        >
                            <div class="flex justify-end px-2 pt-2">
                                <button
                                    type="button"
                                    class="inline-flex size-11 items-center justify-center rounded-lg text-shell-muted hover:bg-row-hover hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    :aria-label="$t('workspace.shell.close_menu')"
                                    @click="drawerOpen = false"
                                >
                                    <XMarkIcon class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                            <WorkspaceSidebar class="flex-1 pt-0" @search="openPalette" />
                        </DialogPanel>
                    </TransitionChild>
                </Dialog>
            </TransitionRoot>

            <div class="min-w-0 flex-1">
                <header
                    v-if="$slots.header"
                    class="px-4 pt-5 text-shell-fg sm:px-6 lg:px-8 [&_h2]:font-medium [&_h2]:tracking-tight [&_h2]:text-shell-fg"
                >
                    <div class="mx-auto max-w-7xl">
                        <slot name="header" />
                    </div>
                </header>

                <main>
                    <PageSkeleton v-if="navigating" />
                    <div v-show="!navigating">
                        <slot />
                    </div>
                </main>
            </div>
            <CommandPalette ref="palette" />
            <UndoToast />
        </div>
    </div>
</template>
