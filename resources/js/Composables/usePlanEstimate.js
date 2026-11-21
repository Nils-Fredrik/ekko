import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * Loads a plan preview and ignores a response that belongs to an older draft.
 * Confirm stays disabled until the latest request has been applied.
 */
export function usePlanEstimate(requestFactory, active = () => true) {
    const estimate = ref(null);
    const error = ref('');
    const estimating = ref(false);
    const appliedKey = ref('');
    let requestId = 0;
    const key = computed(() => JSON.stringify(requestFactory()));

    async function load() {
        if (!active()) {
            return;
        }
        const id = ++requestId;
        const current = key.value;
        estimating.value = true;
        appliedKey.value = '';
        error.value = '';
        try {
            const request = requestFactory();
            const { data } = await window.axios.post(request.url, request.body);
            if (id !== requestId || current !== key.value) {
                return;
            }
            estimate.value = data;
            appliedKey.value = current;
        } catch (caught) {
            if (id !== requestId) {
                return;
            }
            estimate.value = null;
            error.value = failureMessage(caught.response?.data);
        } finally {
            if (id === requestId) {
                estimating.value = false;
            }
        }
    }

    const fresh = computed(() => !estimating.value && appliedKey.value !== '' && appliedKey.value === key.value);

    return { estimate, error, estimating, fresh, load, key };
}

function failureMessage(payload) {
    const text = Object.values(payload?.errors ?? {})
        .flat()
        .join(' ');

    return text || payload?.message || trans('onboarding.ui.estimate_unavailable');
}
