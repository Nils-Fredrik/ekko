<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    terms: String,
});
</script>

<template>
    <Head :title="$t('Terms of Service')" />

    <div class="bg-shell font-sans text-shell-text antialiased">
        <div class="bg-shell pt-4">
            <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
                <div>
                    <AuthenticationCardLogo />
                </div>

                <div
                    class="prose mt-6 w-full overflow-hidden rounded-2xl border border-line bg-shell-panel p-6 text-shell-text sm:max-w-2xl"
                    v-html="terms"
                />
            </div>
        </div>
    </div>
</template>
