<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import { useLocale } from '@/Composables/useLocale';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const providerNames = { google: 'Google' };

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const { locale, locales, setLocale } = useLocale();

const submit = () => {
    form.transform((data) => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="$t('Log in')" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-shell px-4">
        <div class="w-full max-w-sm">
            <div class="flex flex-col items-center text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-xl bg-shell-panel">
                    <img src="/images/maksimer-m-square.svg" alt="" class="logo-mark h-8 w-8" />
                </span>
                <h1 class="mt-5 text-[32px] font-medium tracking-tight text-shell-fg">{{ $t('app.name') }}</h1>
                <p class="mt-1 text-[13px] text-shell-text/50">{{ $t('app.tagline') }}</p>
            </div>

            <div
                v-if="status"
                class="mt-6 flex w-full overflow-hidden rounded-xl border border-shell-fg/[.12] bg-shell-surface"
            >
                <div class="w-1 flex-none bg-success"></div>
                <div class="px-4 py-3 text-[13px] text-shell-text/80">{{ status }}</div>
            </div>

            <div
                v-if="$page.props.errors.sso"
                class="mt-6 flex w-full overflow-hidden rounded-xl border border-shell-fg/[.12] bg-shell-surface"
                role="alert"
            >
                <div class="w-1 flex-none bg-error"></div>
                <div class="px-4 py-3 text-[13px] text-shell-text/80">{{ $page.props.errors.sso }}</div>
            </div>

            <div v-if="$page.props.ssoProviders.length" class="mt-8 flex flex-col gap-3">
                <a
                    v-for="provider in $page.props.ssoProviders"
                    :key="provider"
                    :href="route('social.redirect', { provider })"
                    class="inline-flex w-full items-center justify-center gap-2.5 rounded-full border border-shell-fg/25 px-5 py-3 text-sm font-medium text-shell-fg no-underline transition-colors hover:border-shell-fg hover:bg-shell-fg hover:text-shell"
                >
                    <svg v-if="provider === 'google'" class="h-4 w-4 flex-none" viewBox="0 0 48 48" aria-hidden="true">
                        <path
                            fill="#EA4335"
                            d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
                        />
                        <path
                            fill="#4285F4"
                            d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
                        />
                        <path
                            fill="#FBBC05"
                            d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
                        />
                        <path
                            fill="#34A853"
                            d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
                        />
                    </svg>
                    {{ $t('Log in with :provider', { provider: providerNames[provider] }) }}
                </a>
                <p class="text-center text-[12px] text-shell-text/40">{{ $t('or with email and password') }}</p>
            </div>

            <form class="mt-6" @submit.prevent="submit">
                <div>
                    <label for="email" class="text-sm text-shell-text/70">{{ $t('Email') }}</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        autocomplete="username"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div class="mt-4">
                    <label for="password" class="text-sm text-shell-text/70">{{ $t('Password') }}</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="current-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="mt-4 block">
                    <label class="flex items-center">
                        <input v-model="form.remember" type="checkbox" name="remember" class="rounded" />
                        <span class="ms-2 text-sm text-shell-text/70">{{ $t('Remember me') }}</span>
                    </label>
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm text-shell-accent underline decoration-shell-accent/40 underline-offset-2"
                    >
                        {{ $t('Forgot your password?') }}
                    </Link>
                    <span v-else></span>

                    <button
                        type="submit"
                        class="rounded-full bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-deep disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ $t('Log in') }}
                    </button>
                </div>
            </form>

            <div class="mt-8 flex flex-wrap justify-center gap-x-3 gap-y-1 text-[12px]">
                <button
                    v-for="item in locales"
                    :key="item.code"
                    type="button"
                    :class="item.code === locale ? 'text-shell-fg' : 'text-shell-text/40 hover:text-shell-text/80'"
                    @click="setLocale(item.code)"
                >
                    {{ item.label }}
                </button>
            </div>
        </div>
    </div>
</template>
