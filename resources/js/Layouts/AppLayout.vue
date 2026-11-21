<script setup>
// The shell (docs/ux-arbeidsflate.md 4.1, U17): a 248 px sidebar beside the page. Under 768 px the sidebar is
// a drawer opened from a top strip with the logo, the page title and a menu button.
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { Bars3Icon, XMarkIcon } from '@heroicons/vue/20/solid';
import ActingAsBanner from '@/Components/ActingAsBanner.vue';
import Banner from '@/Components/Banner.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import UndoToast from '@/Components/UI/UndoToast.vue';
import PageSkeleton from '@/Components/UI/PageSkeleton.vue';
import Sidebar from '@/Components/Shell/Sidebar.vue';

defineProps({
    title: String,
});

const menuOpen = ref(false);
const palette = ref(null);

function openPalette() {
    menuOpen.value = false;
    nextTick(() => palette.value?.show());
}
const navigating = ref(false);
let stopNavigationStart;
let stopNavigationFinish;
let stopNavigate;

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
    // The drawer closes when a link in it is followed.
    stopNavigate = router.on('navigate', () => {
        menuOpen.value = false;
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

        <a
            href="#main"
            class="sr-only z-50 rounded-lg bg-shell-surface px-3 py-2 text-sm text-shell-fg focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
        >
            {{ $t('workspace.shell.skip') }}
        </a>

        <ActingAsBanner />

        <Banner />

        <div class="min-h-screen bg-shell text-shell-text md:flex">
            <aside
                class="sticky top-0 hidden h-screen w-[248px] flex-none overflow-y-auto border-r border-line-soft md:block"
            >
                <Sidebar @search="openPalette" />
            </aside>

            <div
                class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line-soft bg-shell/90 px-4 backdrop-blur md:hidden"
            >
                <Link :href="route('dashboard')" class="flex flex-none items-center no-underline">
                    <span class="flex size-7 items-center justify-center rounded-lg bg-shell-panel">
                        <img src="/images/maksimer-m-square.svg" alt="" class="logo-mark size-4" />
                    </span>
                    <span class="sr-only">{{ $t('app.name') }}</span>
                </Link>
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-shell-fg">{{ title }}</span>
                <button
                    type="button"
                    class="inline-flex size-11 flex-none items-center justify-center rounded-lg text-shell-muted hover:bg-row-hover hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-label="$t('app.nav.open_menu')"
                    :aria-expanded="menuOpen"
                    data-testid="open-sidebar"
                    @click="menuOpen = true"
                >
                    <Bars3Icon class="size-5" aria-hidden="true" />
                </button>
            </div>

            <TransitionRoot :show="menuOpen" as="template">
                <Dialog class="relative z-50 md:hidden" @close="menuOpen = false">
                    <TransitionChild
                        as="template"
                        enter="duration-150 ease-out"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="duration-100 ease-in"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <div class="fixed inset-0 bg-black/50" aria-hidden="true"></div>
                    </TransitionChild>
                    <TransitionChild
                        as="template"
                        enter="duration-150 ease-out"
                        enter-from="-translate-x-full"
                        enter-to="translate-x-0"
                        leave="duration-100 ease-in"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-full"
                    >
                        <DialogPanel
                            class="fixed inset-y-0 left-0 flex w-[280px] max-w-[85vw] flex-col overflow-y-auto border-r border-line-soft bg-shell"
                            data-testid="sidebar-drawer"
                        >
                            <div class="flex justify-end px-2 pt-2">
                                <button
                                    type="button"
                                    class="inline-flex size-9 items-center justify-center rounded-lg text-shell-muted hover:bg-row-hover hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    :aria-label="$t('workspace.shell.close_menu')"
                                    @click="menuOpen = false"
                                >
                                    <XMarkIcon class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                            <Sidebar @search="openPalette" />
                        </DialogPanel>
                    </TransitionChild>
                </Dialog>
            </TransitionRoot>

            <div class="min-w-0 flex-1">
                <header
                    v-if="$slots.header"
                    class="border-b border-line-soft px-4 py-5 text-shell-fg sm:px-6 lg:px-8 [&_h2]:font-medium [&_h2]:tracking-tight [&_h2]:text-shell-fg"
                >
                    <div class="mx-auto max-w-7xl">
                        <slot name="header" />
                    </div>
                </header>

                <main id="main" tabindex="-1" class="focus:outline-none">
                    <PageSkeleton v-if="navigating" />
                    <div v-show="!navigating">
                        <slot />
                    </div>
                </main>
            </div>
            <UndoToast />
            <CommandPalette ref="palette" />
        </div>
    </div>
</template>
