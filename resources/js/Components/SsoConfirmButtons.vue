<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Confirm with a linked single sign-on account instead of a password (T07-F2). The page the user is on goes
// along as ?return=, so they come back to it after the provider.
const page = usePage();
const providers = computed(() => page.props.ssoConfirmProviders ?? []);
const names = { google: 'Google' };
const providerName = (provider) => names[provider] ?? provider;
const href = (provider) =>
    route('social.confirm', { provider, return: window.location.pathname + window.location.search });
</script>

<template>
    <div v-if="providers.length" class="mt-4">
        <!-- Only Google can be linked today, so the hint names the first provider. -->
        <p class="text-sm text-gray-600">
            {{ $t('auth.sso.confirm_hint', { provider: providerName(providers[0]) }) }}
        </p>
        <a
            v-for="provider in providers"
            :key="provider"
            :href="href(provider)"
            class="mt-2 inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
            {{ $t('auth.sso.confirm_with', { provider: providerName(provider) }) }}
        </a>
    </div>
</template>
