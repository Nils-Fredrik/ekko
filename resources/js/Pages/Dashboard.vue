<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ botLogUploadsUrl: String });
</script>

<template>
    <AppLayout :title="$t('Dashboard')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('Dashboard') }}</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="rounded-2xl border border-line bg-white p-6 text-ink lg:p-8">
                    <h1 class="text-2xl font-medium tracking-tight">{{ $t('app.name') }}</h1>
                    <p class="mt-4 text-sm text-ink-soft">{{ $t('app.tagline') }}</p>
                    <Link v-if="botLogUploadsUrl" :href="botLogUploadsUrl" class="mt-4 inline-block text-sm underline">
                        {{ $t('bot_logs.title') }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
