<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import InputError from '@/Components/InputError.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    team: Object,
});

const confirmingTeamDeletion = ref(false);
const form = useForm({});

const confirmTeamDeletion = () => {
    confirmingTeamDeletion.value = true;
};

const deleteTeam = () => {
    form.delete(route('teams.destroy', props.team), {
        errorBag: 'deleteTeam',
        onFinish: () => (confirmingTeamDeletion.value = false),
    });
};
</script>

<template>
    <ActionSection>
        <template #title>{{ $t('app.tenant_close.title') }}</template>

        <template #description>{{ $t('app.tenant_close.description') }}</template>

        <template #content>
            <div class="max-w-xl text-sm text-gray-600">{{ $t('app.tenant_close.body') }}</div>

            <!-- Closing needs a recently confirmed password (T39-F6). -->
            <div class="mt-5">
                <ConfirmsPassword @confirmed="confirmTeamDeletion">
                    <DangerButton type="button">{{ $t('app.tenant_close.title') }}</DangerButton>
                </ConfirmsPassword>
                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <!-- Delete Team Confirmation Modal -->
            <ConfirmationModal :show="confirmingTeamDeletion" @close="confirmingTeamDeletion = false">
                <template #title>{{ $t('app.tenant_close.title') }}</template>

                <template #content>{{ $t('app.tenant_close.confirm') }}</template>

                <template #footer>
                    <SecondaryButton @click="confirmingTeamDeletion = false">{{ $t('Cancel') }}</SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteTeam"
                    >
                        {{ $t('app.tenant_close.title') }}
                    </DangerButton>
                </template>
            </ConfirmationModal>
        </template>
    </ActionSection>
</template>
