<template>
    <div class="flex min-h-screen flex-col items-center bg-shell px-4 pt-6 sm:justify-center sm:pt-0">
        <div>
            <slot name="logo" />
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-2xl border border-line bg-shell-panel px-6 py-4 text-shell-text sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
