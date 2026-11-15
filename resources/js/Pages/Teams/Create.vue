<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import CreateTeamForm from '@/Pages/Teams/Partials/CreateTeamForm.vue';
</script>

<template>
    <AppLayout :title="$t('Create Team')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('Create Team') }}</h2>
        </template>

        <div>
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
                <CreateTeamForm />
            </div>
        </div>
    </AppLayout>
</template>
