<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';

// The consent page of the Claude connection (docs/spec.md 15.2, ADR 0016). A plain form post, not an Inertia
// visit: the answer is a redirect to Claude's callback on another site.
const props = defineProps({
    error: String,
    client: String,
    host: String,
    loopback: Boolean,
    user: Object,
    tenants: Array,
    defaultTenant: Number,
    writeRequested: Boolean,
    params: Object,
    csrf: String,
});

const tenant = ref(props.defaultTenant);
const allowWrite = ref(false);
const chosen = computed(() => (props.tenants ?? []).find((t) => t.id === tenant.value));
</script>

<template>
    <Head :title="$t('mcp.authorize.title')" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-shell px-4 py-10">
        <div class="w-full max-w-md rounded-2xl border border-line bg-white p-6 text-ink">
            <template v-if="error">
                <h1 class="text-xl font-medium tracking-tight">{{ $t('mcp.authorize.error_title') }}</h1>
                <p class="mt-3 text-sm text-ink-soft">{{ error }}</p>
            </template>

            <template v-else>
                <h1 class="text-xl font-medium tracking-tight">{{ $t('mcp.authorize.heading', { client }) }}</h1>
                <p class="mt-3 text-sm text-ink-soft">{{ $t('mcp.authorize.intro') }}</p>
                <p class="mt-2 text-xs text-ink-soft">
                    {{ loopback ? $t('mcp.authorize.loopback') : $t('mcp.authorize.host', { host }) }}
                </p>
                <p class="mt-2 text-xs text-ink-soft">
                    {{ $t('mcp.authorize.signed_in_as', { name: user.name, email: user.email }) }}
                </p>

                <form method="post" :action="route('mcp.oauth.decide')" class="mt-6 space-y-5">
                    <input type="hidden" name="_token" :value="csrf" />
                    <input v-for="(value, key) in params" :key="key" type="hidden" :name="key" :value="value" />

                    <label class="block text-sm font-medium">
                        {{ $t('mcp.authorize.tenant_label') }}
                        <select v-model="tenant" name="tenant" class="mt-1 block w-full rounded-lg border-line text-sm">
                            <option v-for="t in tenants" :key="t.id" :value="t.id">
                                {{ t.name }}{{ t.role ? ` · ${$t(`roles.${t.role}.name`)}` : '' }}
                            </option>
                        </select>
                    </label>
                    <p v-if="chosen && chosen.clients > 0" class="text-xs text-ink-soft">
                        {{ $t('mcp.authorize.tenant_clients', { count: chosen.clients }) }}
                    </p>

                    <fieldset v-if="writeRequested">
                        <legend class="text-sm font-medium">{{ $t('mcp.authorize.access_label') }}</legend>
                        <label class="mt-2 flex items-start gap-2 text-sm">
                            <input v-model="allowWrite" type="radio" :value="false" class="mt-1" />
                            <span>
                                {{ $t('mcp.authorize.read') }}
                                <span class="block text-xs text-ink-soft">{{ $t('mcp.authorize.read_help') }}</span>
                            </span>
                        </label>
                        <label class="mt-2 flex items-start gap-2 text-sm">
                            <input v-model="allowWrite" type="radio" :value="true" class="mt-1" />
                            <span>
                                {{ $t('mcp.authorize.write') }}
                                <span class="block text-xs text-ink-soft">{{ $t('mcp.authorize.write_help') }}</span>
                            </span>
                        </label>
                        <input type="hidden" name="allow_write" :value="allowWrite ? '1' : '0'" />
                    </fieldset>

                    <div class="flex justify-end gap-3">
                        <button
                            type="submit"
                            name="decision"
                            value="deny"
                            class="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {{ $t('mcp.authorize.deny') }}
                        </button>
                        <button
                            type="submit"
                            name="decision"
                            value="approve"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-white"
                        >
                            {{ $t('mcp.authorize.approve') }}
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>
