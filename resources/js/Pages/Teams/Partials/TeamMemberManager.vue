<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    team: Object,
    availableRoles: Array,
    userPermissions: Object,
});

const page = usePage();

const addTeamMemberForm = useForm({
    email: '',
    role: null,
});

const updateRoleForm = useForm({
    role: null,
});

const leaveTeamForm = useForm({});
const removeTeamMemberForm = useForm({});

const currentlyManagingRole = ref(false);
const managingRoleFor = ref(null);
const confirmingLeavingTeam = ref(false);
const teamMemberBeingRemoved = ref(null);

const addTeamMember = () => {
    addTeamMemberForm.post(route('team-members.store', props.team), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => addTeamMemberForm.reset(),
    });
};

const cancelTeamInvitation = (invitation) => {
    router.delete(route('team-invitations.destroy', invitation), {
        preserveScroll: true,
    });
};

const manageRole = (teamMember) => {
    managingRoleFor.value = teamMember;
    updateRoleForm.role = teamMember.membership.role;
    currentlyManagingRole.value = true;
};

const updateRole = () => {
    updateRoleForm.put(route('team-members.update', [props.team, managingRoleFor.value]), {
        preserveScroll: true,
        onSuccess: () => (currentlyManagingRole.value = false),
    });
};

const confirmLeavingTeam = () => {
    confirmingLeavingTeam.value = true;
};

const leaveTeam = () => {
    leaveTeamForm.delete(route('team-members.destroy', [props.team, page.props.auth.user]));
};

const confirmTeamMemberRemoval = (teamMember) => {
    teamMemberBeingRemoved.value = teamMember;
};

const removeTeamMember = () => {
    removeTeamMemberForm.delete(route('team-members.destroy', [props.team, teamMemberBeingRemoved.value]), {
        errorBag: 'removeTeamMember',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (teamMemberBeingRemoved.value = null),
    });
};

const members = computed(() => [
    props.team.owner,
    ...props.team.users.filter((user) => user.id !== props.team.owner.id),
]);

const displayableRole = (role) =>
    props.availableRoles.find((option) => option.key === role)?.name ?? 'Role not specified';
</script>

<template>
    <div>
        <SectionBorder />
        <ActionSection class="mt-10 sm:mt-0">
            <template #title>{{ $t('Team Members') }}</template>
            <template #description>{{ $t('All of the people that are part of this team.') }}</template>
            <template #content>
                <ul :aria-label="$t('Team Members')" class="divide-y divide-line-soft">
                    <li
                        v-for="user in members"
                        :key="user.id"
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words text-shell-fg">{{ user.name }}</p>
                            <p class="text-sm break-all text-shell-muted">{{ user.email }}</p>
                        </div>
                        <span v-if="user.id === team.owner.id" class="text-sm text-shell-text">{{ $t('Owner') }}</span>
                        <div v-else class="flex flex-wrap items-center gap-3 text-sm">
                            <button
                                v-if="userPermissions.canUpdateTeamMembers && availableRoles.length"
                                type="button"
                                class="text-shell-accent underline"
                                @click="manageRole(user)"
                            >
                                {{ $t(displayableRole(user.membership.role)) }}
                            </button>
                            <span v-else class="text-shell-text">{{ $t(displayableRole(user.membership.role)) }}</span>

                            <button
                                v-if="page.props.auth.user.id === user.id"
                                type="button"
                                class="text-error"
                                @click="confirmLeavingTeam"
                            >
                                {{ $t('Leave') }}
                            </button>
                            <button
                                v-else-if="userPermissions.canRemoveTeamMembers"
                                type="button"
                                class="text-error"
                                @click="confirmTeamMemberRemoval(user)"
                            >
                                {{ $t('Remove') }}
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-if="members.length === 1" class="mt-3 text-sm text-shell-muted">
                    {{ $t('No other team members yet.') }}
                </p>
            </template>
        </ActionSection>

        <div v-if="userPermissions.canAddTeamMembers">
            <SectionBorder />

            <!-- Add Team Member -->
            <FormSection @submitted="addTeamMember">
                <template #title>{{ $t('Add Team Member') }}</template>

                <template #description>
                    {{ $t('Add a new team member to your team, allowing them to collaborate with you.') }}
                </template>

                <template #form>
                    <div class="col-span-6">
                        <div class="max-w-xl text-sm text-gray-600">
                            {{
                                $t('Please provide the email address of the person you would like to add to this team.')
                            }}
                        </div>
                    </div>

                    <!-- Member Email -->
                    <div class="col-span-6 sm:col-span-4">
                        <InputLabel for="email" :value="$t('Email')" />
                        <TextInput
                            id="email"
                            v-model="addTeamMemberForm.email"
                            type="email"
                            class="mt-1 block w-full"
                        />
                        <InputError :message="addTeamMemberForm.errors.email" class="mt-2" />
                    </div>

                    <!-- Role -->
                    <div v-if="availableRoles.length > 0" class="col-span-6 lg:col-span-4">
                        <InputLabel for="roles" :value="$t('Role')" />
                        <InputError :message="addTeamMemberForm.errors.role" class="mt-2" />

                        <div class="relative z-0 mt-1 border border-gray-200 rounded-lg cursor-pointer">
                            <button
                                v-for="(role, i) in availableRoles"
                                :key="role.key"
                                type="button"
                                class="relative px-4 py-3 inline-flex w-full rounded-lg focus:z-10 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                                :class="{
                                    'border-t border-gray-200 focus:border-none rounded-t-none': i > 0,
                                    'rounded-b-none': i != Object.keys(availableRoles).length - 1,
                                }"
                                @click="addTeamMemberForm.role = role.key"
                            >
                                <div
                                    :class="{
                                        'opacity-50': addTeamMemberForm.role && addTeamMemberForm.role != role.key,
                                    }"
                                >
                                    <!-- Role Name -->
                                    <div class="flex items-center">
                                        <div
                                            class="text-sm text-gray-600"
                                            :class="{ 'font-semibold': addTeamMemberForm.role == role.key }"
                                        >
                                            {{ $t(role.name) }}
                                        </div>

                                        <svg
                                            v-if="addTeamMemberForm.role == role.key"
                                            class="ms-2 size-5 text-green-400"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="1.5"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                            />
                                        </svg>
                                    </div>

                                    <!-- Role Description -->
                                    <div class="mt-2 text-xs text-gray-600 text-start">
                                        {{ $t(role.description) }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                </template>

                <template #actions>
                    <ActionMessage :on="addTeamMemberForm.recentlySuccessful" class="me-3">
                        {{ $t('Added.') }}
                    </ActionMessage>

                    <PrimaryButton
                        :class="{ 'opacity-25': addTeamMemberForm.processing }"
                        :disabled="addTeamMemberForm.processing"
                    >
                        {{ $t('Add') }}
                    </PrimaryButton>
                </template>
            </FormSection>
        </div>

        <div v-if="team.team_invitations.length > 0 && userPermissions.canAddTeamMembers">
            <SectionBorder />

            <!-- Team Member Invitations -->
            <ActionSection class="mt-10 sm:mt-0">
                <template #title>{{ $t('Pending Team Invitations') }}</template>

                <template #description>
                    {{
                        $t(
                            'These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.',
                        )
                    }}
                </template>

                <!-- Pending Team Member Invitation List -->
                <template #content>
                    <ul :aria-label="$t('Pending Team Invitations')" class="space-y-4">
                        <li
                            v-for="invitation in team.team_invitations"
                            :key="invitation.id"
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <p class="break-all text-shell-text">{{ invitation.email }}</p>
                                <p class="text-sm text-shell-muted">{{ $t(displayableRole(invitation.role)) }}</p>
                            </div>
                            <button
                                v-if="userPermissions.canRemoveTeamMembers"
                                type="button"
                                class="text-sm text-error"
                                @click="cancelTeamInvitation(invitation)"
                            >
                                {{ $t('Cancel') }}
                            </button>
                        </li>
                    </ul>
                </template>
            </ActionSection>
        </div>

        <!-- Role Management Modal -->
        <DialogModal :show="currentlyManagingRole" @close="currentlyManagingRole = false">
            <template #title>{{ $t('Manage Role') }}</template>

            <template #content>
                <div v-if="managingRoleFor">
                    <div class="relative z-0 mt-1 border border-gray-200 rounded-lg cursor-pointer">
                        <button
                            v-for="(role, i) in availableRoles"
                            :key="role.key"
                            type="button"
                            class="relative px-4 py-3 inline-flex w-full rounded-lg focus:z-10 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                            :class="{
                                'border-t border-gray-200 focus:border-none rounded-t-none': i > 0,
                                'rounded-b-none': i !== Object.keys(availableRoles).length - 1,
                            }"
                            @click="updateRoleForm.role = role.key"
                        >
                            <div :class="{ 'opacity-50': updateRoleForm.role && updateRoleForm.role !== role.key }">
                                <!-- Role Name -->
                                <div class="flex items-center">
                                    <div
                                        class="text-sm text-gray-600"
                                        :class="{ 'font-semibold': updateRoleForm.role === role.key }"
                                    >
                                        {{ $t(role.name) }}
                                    </div>

                                    <svg
                                        v-if="updateRoleForm.role == role.key"
                                        class="ms-2 size-5 text-green-400"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                        />
                                    </svg>
                                </div>

                                <!-- Role Description -->
                                <div class="mt-2 text-xs text-gray-600">
                                    {{ $t(role.description) }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="currentlyManagingRole = false">{{ $t('Cancel') }}</SecondaryButton>

                <PrimaryButton
                    class="ms-3"
                    :class="{ 'opacity-25': updateRoleForm.processing }"
                    :disabled="updateRoleForm.processing"
                    @click="updateRole"
                >
                    {{ $t('Save') }}
                </PrimaryButton>
            </template>
        </DialogModal>

        <!-- Leave Team Confirmation Modal -->
        <ConfirmationModal :show="confirmingLeavingTeam" @close="confirmingLeavingTeam = false">
            <template #title>{{ $t('Leave Team') }}</template>

            <template #content>{{ $t('Are you sure you would like to leave this team?') }}</template>

            <template #footer>
                <SecondaryButton @click="confirmingLeavingTeam = false">{{ $t('Cancel') }}</SecondaryButton>

                <DangerButton
                    class="ms-3"
                    :class="{ 'opacity-25': leaveTeamForm.processing }"
                    :disabled="leaveTeamForm.processing"
                    @click="leaveTeam"
                >
                    {{ $t('Leave') }}
                </DangerButton>
            </template>
        </ConfirmationModal>

        <!-- Remove Team Member Confirmation Modal -->
        <ConfirmationModal :show="teamMemberBeingRemoved" @close="teamMemberBeingRemoved = null">
            <template #title>{{ $t('Remove Team Member') }}</template>

            <template #content>{{ $t('Are you sure you would like to remove this person from the team?') }}</template>

            <template #footer>
                <SecondaryButton @click="teamMemberBeingRemoved = null">{{ $t('Cancel') }}</SecondaryButton>

                <DangerButton
                    class="ms-3"
                    :class="{ 'opacity-25': removeTeamMemberForm.processing }"
                    :disabled="removeTeamMemberForm.processing"
                    @click="removeTeamMember"
                >
                    {{ $t('Remove') }}
                </DangerButton>
            </template>
        </ConfirmationModal>
    </div>
</template>
