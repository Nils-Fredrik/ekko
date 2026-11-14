<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <AppLayout :title="$t('Dashboard')">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $t('Dashboard') }}</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                    <h1 class="text-2xl font-medium text-gray-900">{{ $t('app.name') }}</h1>
                    <p class="mt-4 text-gray-500">{{ $t('app.tagline') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
