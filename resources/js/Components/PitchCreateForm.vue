<script setup>
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    admin: Boolean,
    partners: { type: Array, default: () => [] },
    plans: { type: Array, default: () => [] },
});
const form = useForm({ name: '', partner_team_id: props.partners[0]?.id ?? '', plan_id: props.plans[0]?.id ?? '' });
function submit() {
    form.post(route(props.admin ? 'admin.pitches.store' : 'partner.pitches.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
}
</script>

<template>
    <form class="space-y-3 rounded-xl border border-line bg-shell-panel p-5" @submit.prevent="submit">
        <h3 class="font-medium">{{ $t('pitch.create') }}</h3>
        <label class="block text-sm" for="pitch-name">{{ $t('pitch.name') }}</label>
        <TextInput id="pitch-name" v-model="form.name" required maxlength="255" />
        <InputError :message="form.errors.name || form.errors.pitch" />
        <template v-if="admin">
            <label class="block text-sm" for="pitch-partner">{{ $t('pitch.partner') }}</label>
            <select id="pitch-partner" v-model="form.partner_team_id" required>
                <option v-for="partner in partners" :key="partner.id" :value="partner.id">{{ partner.name }}</option>
            </select>
            <InputError :message="form.errors.partner_team_id" />
            <label class="block text-sm" for="pitch-plan">{{ $t('pitch.plan') }}</label>
            <select id="pitch-plan" v-model="form.plan_id" required>
                <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
            </select>
            <InputError :message="form.errors.plan_id" />
        </template>
        <div>
            <PrimaryButton :disabled="form.processing">{{ $t('pitch.create') }}</PrimaryButton>
        </div>
    </form>
</template>
