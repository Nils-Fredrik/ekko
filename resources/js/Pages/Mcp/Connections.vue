<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

// "Claude": how to connect Claude to Ekko, and the user's own connections (docs/spec.md 15.2, ADR 0016).
const props = defineProps({
    serverUrl: String,
    connections: Array,
});

// A command, not a user text: the same in every language.
const claudeCodeCommand = computed(() => `claude mcp add --transport http ekko ${props.serverUrl}`);

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '–');

const revoke = (connection, message) => {
    if (window.confirm(message)) {
        router.delete(route('mcp.connections.destroy', connection.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :title="$t('mcp.connections.title')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('mcp.connections.title') }}
            </h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 py-10 sm:px-6 lg:px-8">
            <section class="rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <p class="text-sm text-shell-muted">{{ $t('mcp.connections.intro') }}</p>
                <p class="mt-4 text-sm font-medium">{{ $t('mcp.connections.server_url') }}</p>
                <code class="mt-1 block rounded-lg bg-shell-surface px-3 py-2 text-sm break-all">{{ serverUrl }}</code>

                <h3 class="mt-6 text-sm font-medium">{{ $t('mcp.connections.how_title') }}</h3>
                <p class="mt-2 text-sm text-shell-muted">{{ $t('mcp.connections.how_claude_ai') }}</p>
                <p class="mt-2 text-sm text-shell-muted">{{ $t('mcp.connections.how_claude_code') }}</p>
                <code class="mt-1 block rounded-lg bg-shell-surface px-3 py-2 text-sm break-all">
                    {{ claudeCodeCommand }}
                </code>
            </section>

            <section class="rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <h3 class="text-sm font-medium">{{ $t('mcp.connections.list_title') }}</h3>
                <EmptyState
                    v-if="connections.length === 0"
                    class="mt-3"
                    :title="$t('app.empty.connections_title')"
                    :body="$t('app.empty.connections_body')"
                />
                <table v-else class="mt-4 w-full text-left text-sm">
                    <thead class="text-xs text-shell-muted">
                        <tr>
                            <th class="py-2 pr-3">{{ $t('mcp.nav') }}</th>
                            <th class="py-2 pr-3">{{ $t('mcp.connections.tenant') }}</th>
                            <th class="py-2 pr-3">{{ $t('mcp.connections.access') }}</th>
                            <th class="py-2 pr-3">{{ $t('mcp.connections.connected') }}</th>
                            <th class="py-2 pr-3">{{ $t('mcp.connections.last_used') }}</th>
                            <th class="py-2 pr-3">{{ $t('mcp.connections.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="connection in connections" :key="connection.id" class="border-t border-line-soft">
                            <td class="py-2 pr-3">
                                {{ connection.client }}
                                <span class="block text-xs text-shell-muted">{{ connection.host }}</span>
                            </td>
                            <td class="py-2 pr-3">{{ connection.tenant }}</td>
                            <td class="py-2 pr-3">
                                {{
                                    connection.write
                                        ? $t('mcp.connections.read_write')
                                        : $t('mcp.connections.read_only')
                                }}
                            </td>
                            <td class="py-2 pr-3">{{ formatDate(connection.created_at) }}</td>
                            <td class="py-2 pr-3">{{ formatDate(connection.last_used_at) }}</td>
                            <td class="py-2 pr-3">
                                <span v-if="connection.revoked_at" class="text-shell-muted">
                                    {{ $t('mcp.connections.revoked_at', { date: formatDate(connection.revoked_at) }) }}
                                </span>
                                <button
                                    v-else
                                    type="button"
                                    class="text-primary underline"
                                    @click="revoke(connection, $t('mcp.connections.confirm_revoke'))"
                                >
                                    {{ $t('mcp.connections.revoke') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
