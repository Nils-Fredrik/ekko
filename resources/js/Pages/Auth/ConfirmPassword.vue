<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const form = useForm({
    password: '',
});

const passwordInput = ref(null);

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();

            passwordInput.value.focus();
        },
    });
};

const providerName = (provider) => ({ google: 'Google' })[provider] ?? provider;
</script>

<template>
    <Head :title="$t('jetstream.secure_area')" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <div class="mb-4 text-sm text-gray-600">
            {{ $t('This is a secure area of the application. Please confirm your password before continuing.') }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="password" :value="$t('Password')" />
                <TextInput
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div v-if="$page.props.ssoConfirmProviders?.length" class="mt-4">
                <p class="text-sm text-gray-600">
                    {{ $t('auth.sso.confirm_hint', { provider: providerName($page.props.ssoConfirmProviders[0]) }) }}
                </p>
                <a
                    v-for="provider in $page.props.ssoConfirmProviders"
                    :key="provider"
                    :href="route('social.confirm', { provider })"
                    class="mt-2 inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    {{ $t('auth.sso.confirm_with', { provider: providerName(provider) }) }}
                </a>
            </div>

            <div class="flex justify-end mt-4">
                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ $t('Confirm') }}
                </PrimaryButton>
            </div>
        </form>
    </AuthenticationCard>
</template>
