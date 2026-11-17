<script setup>
import { useDateTime } from '@/Composables/useDateTime';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const { dateTime } = useDateTime();

const props = defineProps({
    team: { type: Object, required: true },
});

const page = usePage();
const profile = computed(() => page.props.teamProfile);

const slack = useForm({ webhook_url: '', clear: false });
const branding = useForm({ primary: profile.value?.primary ?? '', logo: null });
const key = useForm({ provider: 'openai', key: '' });
const budget = useForm({ budget_usd: profile.value?.byok_budget ?? '' });

function saveSlack() {
    slack
        .transform((data) => ({
            webhook_url: data.clear ? '' : data.webhook_url,
            clear: data.clear,
        }))
        .put(route('teams.slack.update', props.team.id), {
            preserveScroll: true,
            errorBag: 'slack',
            onSuccess: () => slack.reset('webhook_url', 'clear'),
        });
}

function saveBranding() {
    branding
        .transform((data) => {
            const payload = { ...data, _method: 'put' };
            if (!payload.logo) {
                delete payload.logo;
            }
            return payload;
        })
        .post(route('teams.branding.update', props.team.id), {
            preserveScroll: true,
            errorBag: 'branding',
            forceFormData: true,
        });
}

function saveKey() {
    key.put(route('teams.api-keys.store', props.team.id), {
        preserveScroll: true,
        errorBag: 'apiKey',
        onSuccess: () => key.reset('key'),
    });
}

function saveBudget() {
    budget.put(route('teams.byok-budget.update', props.team.id), { preserveScroll: true, errorBag: 'budget' });
}
</script>

<template>
    <div v-if="profile" class="mt-10 space-y-10">
        <FormSection @submitted="saveSlack">
            <template #title>{{ $t('app.workspace.slack') }}</template>
            <template #description>{{ $t('app.workspace.slack_help') }}</template>
            <template #form>
                <div class="col-span-6 sm:col-span-4">
                    <p class="text-sm text-gray-600">
                        {{
                            profile.slack_configured
                                ? $t('app.workspace.slack_configured')
                                : $t('app.workspace.slack_missing')
                        }}
                    </p>
                    <InputLabel class="mt-4" for="webhook_url" :value="$t('app.workspace.slack_url')" />
                    <TextInput
                        id="webhook_url"
                        v-model="slack.webhook_url"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="off"
                        :disabled="!profile.can_update"
                    />
                    <InputError :message="slack.errors.webhook_url" class="mt-2" />
                    <label
                        v-if="profile.slack_configured && profile.can_update"
                        class="mt-3 flex items-center gap-2 text-sm"
                    >
                        <input v-model="slack.clear" type="checkbox" />
                        {{ $t('app.workspace.clear_slack') }}
                    </label>
                    <InputError :message="slack.errors.clear" class="mt-2" />
                </div>
            </template>
            <template v-if="profile.can_update" #actions>
                <ActionMessage :on="slack.recentlySuccessful" class="me-3">{{ $t('Saved.') }}</ActionMessage>
                <PrimaryButton :disabled="slack.processing">{{ $t('app.workspace.save') }}</PrimaryButton>
            </template>
        </FormSection>

        <FormSection @submitted="saveBranding">
            <template #title>{{ $t('app.workspace.report_profile') }}</template>
            <template #description>{{ $t('app.workspace.report_profile_help') }}</template>
            <template #form>
                <div class="col-span-6 sm:col-span-4">
                    <InputLabel for="primary" :value="$t('app.workspace.primary_color')" />
                    <TextInput
                        id="primary"
                        v-model="branding.primary"
                        type="text"
                        class="mt-1 block w-full"
                        :disabled="!profile.can_update"
                    />
                    <InputError :message="branding.errors.primary" class="mt-2" />
                    <InputLabel class="mt-4" for="logo" :value="$t('reports.logo')" />
                    <input
                        id="logo"
                        type="file"
                        accept="image/png,image/jpeg"
                        class="mt-1 block w-full text-sm"
                        :disabled="!profile.can_update"
                        @input="branding.logo = $event.target.files[0]"
                    />
                    <p class="mt-1 text-sm text-gray-600">{{ $t('reports.logo_help') }}</p>
                    <p v-if="profile.has_logo" class="mt-1 text-sm text-gray-600">{{ $t('reports.logo') }}</p>
                    <InputError :message="branding.errors.logo" class="mt-2" />
                </div>
            </template>
            <template v-if="profile.can_update" #actions>
                <ActionMessage :on="branding.recentlySuccessful" class="me-3">{{ $t('Saved.') }}</ActionMessage>
                <PrimaryButton :disabled="branding.processing">{{ $t('app.workspace.save') }}</PrimaryButton>
            </template>
        </FormSection>

        <FormSection @submitted="saveKey">
            <template #title>{{ $t('app.workspace.api_keys') }}</template>
            <template #description>{{ $t('app.workspace.api_keys_help') }}</template>
            <template #form>
                <div class="col-span-6 space-y-2 sm:col-span-4">
                    <p v-if="!profile.byok_enabled" class="text-sm text-gray-600">{{ $t('app.workspace.byok_off') }}</p>
                    <p v-for="item in profile.keys ?? []" :key="item.provider" class="text-sm text-gray-700">
                        {{ $t(`app.workspace.providers.${item.provider}`) }}:
                        {{
                            item.configured
                                ? item.verified_at
                                    ? $t('app.workspace.verified', { time: dateTime(item.verified_at) })
                                    : $t('app.workspace.not_set')
                                : $t('app.workspace.not_set')
                        }}
                    </p>
                    <template v-if="profile.byok_enabled && profile.can_update">
                        <InputLabel for="provider" :value="$t('app.workspace.provider')" />
                        <select
                            id="provider"
                            v-model="key.provider"
                            class="mt-1 block w-full rounded-md border-gray-300"
                        >
                            <option v-for="item in profile.keys ?? []" :key="item.provider" :value="item.provider">
                                {{ $t(`app.workspace.providers.${item.provider}`) }}
                            </option>
                        </select>
                        <InputLabel class="mt-4" for="api_key" :value="$t('app.workspace.api_key')" />
                        <TextInput
                            id="api_key"
                            v-model="key.key"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="off"
                        />
                        <InputError :message="key.errors.key" class="mt-2" />
                        <InputError :message="key.errors.provider" class="mt-2" />
                    </template>
                </div>
            </template>
            <template v-if="profile.byok_enabled && profile.can_update" #actions>
                <ActionMessage :on="key.recentlySuccessful" class="me-3">{{ $t('Saved.') }}</ActionMessage>
                <PrimaryButton :disabled="key.processing">{{ $t('app.workspace.save') }}</PrimaryButton>
            </template>
        </FormSection>

        <FormSection v-if="profile.byok_enabled && profile.byok_budget !== undefined" @submitted="saveBudget">
            <template #title>{{ $t('app.workspace.budget') }}</template>
            <template #description>{{ $t('app.workspace.byok_budget') }}</template>
            <template #form>
                <div class="col-span-6 sm:col-span-4">
                    <InputLabel for="budget_usd" :value="$t('app.workspace.byok_budget')" />
                    <TextInput
                        id="budget_usd"
                        v-model="budget.budget_usd"
                        type="text"
                        class="mt-1 block w-full"
                        :disabled="!profile.can_update"
                    />
                    <InputError :message="budget.errors.budget_usd" class="mt-2" />
                </div>
            </template>
            <template v-if="profile.can_update" #actions>
                <ActionMessage :on="budget.recentlySuccessful" class="me-3">{{ $t('Saved.') }}</ActionMessage>
                <PrimaryButton :disabled="budget.processing">{{ $t('app.workspace.save') }}</PrimaryButton>
            </template>
        </FormSection>
    </div>
</template>
