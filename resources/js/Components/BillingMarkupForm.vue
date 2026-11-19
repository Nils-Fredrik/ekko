<script setup>
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ tenantId: Number, value: String, name: String });
const form = useForm({ markup_percent: props.value ?? '' });
const save = () => form.put(route('admin.billing.markup', props.tenantId), { preserveScroll: true });
</script>

<template>
    <form class="relative min-w-48" @submit.prevent="save">
        <label :for="`markup-${tenantId}`" class="sr-only">{{ $t('billing.markup_for', { name }) }}</label>
        <div class="flex items-center gap-2">
            <TextInput
                :id="`markup-${tenantId}`"
                v-model="form.markup_percent"
                type="number"
                min="0"
                max="99999.9999"
                step="0.0001"
                class="w-28 text-sm"
            />
            <PrimaryButton :disabled="form.processing">{{ $t('billing.save') }}</PrimaryButton>
        </div>
        <InputError :message="form.errors.markup_percent" class="mt-2" />
        <p v-if="form.recentlySuccessful" role="status" class="mt-2 text-sm text-shell-muted">
            {{ $t('billing.saved') }}
        </p>
    </form>
</template>
