import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, getCurrentInstance, onBeforeUnmount, reactive, toValue } from 'vue';

/** How long «Lagret» stays before the field returns to idle. */
export const SAVED_VISIBLE_MS = 2000;

/** A rejected save. `errors` holds the validation messages per field. */
export class SaveError extends Error {
    constructor(errors = {}) {
        super('save failed');
        this.errors = errors;
    }
}

/**
 * Sends one field with Inertia and resolves when the server accepted it. Validation errors reject
 * with a SaveError; a server or network error rejects with an empty one.
 */
export function inertiaRequest(method, url, data, { only = [] } = {}) {
    return new Promise((resolve, reject) => {
        let settled = false;
        router[method](url, data, {
            preserveState: true,
            preserveScroll: true,
            only,
            onSuccess: () => {
                settled = true;
                resolve();
            },
            onError: (errors) => {
                settled = true;
                reject(new SaveError(errors));
            },
            onFinish: () => {
                if (!settled) {
                    reject(new SaveError());
                }
            },
        });
    });
}

/**
 * The rules behind useAutosave, without Inertia or a component, so tests/js can run them.
 * `values` is the model the view shows; it changes before the answer and rolls back on an error.
 */
export function createAutosave({
    url,
    method = 'patch',
    only = [],
    values = reactive({}),
    request = inertiaRequest,
    setTimer = (callback, ms) => setTimeout(callback, ms),
    clearTimer = (timer) => clearTimeout(timer),
} = {}) {
    const statuses = reactive({});
    const errors = reactive({});
    const latest = {};
    const timers = {};
    // The last value the server accepted per field, and which save it came from. A failed save
    // rolls back to it, never to a value that is still waiting for its answer.
    const confirmed = {};
    const confirmedToken = {};

    function status(field) {
        return statuses[field] ?? 'idle';
    }

    async function save(field, value) {
        if (Object.is(values[field], value) && status(field) !== 'error') {
            return true;
        }
        if (!(field in confirmed)) {
            confirmed[field] = values[field];
            confirmedToken[field] = latest[field] ?? 0;
        }
        const token = (latest[field] ?? 0) + 1;
        latest[field] = token;
        clearTimer(timers[field]);
        values[field] = value;
        statuses[field] = 'saving';
        delete errors[field];

        try {
            await request(method, toValue(url), { [field]: value }, { only });
            if (token > confirmedToken[field]) {
                confirmed[field] = value;
                confirmedToken[field] = token;
            }
            if (latest[field] !== token) {
                return true;
            }
            statuses[field] = 'saved';
            timers[field] = setTimer(() => {
                if (latest[field] === token && statuses[field] === 'saved') {
                    statuses[field] = 'idle';
                }
            }, SAVED_VISIBLE_MS);

            return true;
        } catch (error) {
            if (latest[field] !== token) {
                return false;
            }
            values[field] = confirmed[field];
            statuses[field] = 'error';
            const messages = error instanceof SaveError ? error.errors : {};
            const message = messages[field] ?? Object.values(messages)[0];
            errors[field] = Array.isArray(message) ? message[0] : (message ?? trans('workspace.save.failed'));

            return false;
        }
    }

    /** One state for a panel: saving before error before saved. */
    const state = computed(() => {
        const all = Object.values(statuses);
        for (const candidate of ['saving', 'error', 'saved']) {
            if (all.includes(candidate)) {
                return candidate;
            }
        }

        return 'idle';
    });
    const error = computed(() => Object.values(errors)[0] ?? '');

    /** Clears the statuses, for example when the panel shows another row. */
    function reset() {
        for (const field of Object.keys(statuses)) {
            clearTimer(timers[field]);
            delete statuses[field];
            delete errors[field];
            latest[field] = (latest[field] ?? 0) + 1;
        }
        for (const field of Object.keys(confirmed)) {
            delete confirmed[field];
            delete confirmedToken[field];
        }
    }

    return {
        values,
        statuses,
        errors,
        state,
        error,
        status,
        save,
        reset,
        dispose: () => Object.values(timers).forEach(clearTimer),
    };
}

/**
 * Saves single fields where they change (docs/ux-arbeidsflate.md 4.5):
 *
 *   const { values, save, status, errors, state } = useAutosave(() => route('…', id), { values: reactive({ … }) });
 *   <input :value="values.name" @blur="save('name', $event.target.value)" @keydown.enter.prevent="$event.target.blur()">
 *   <select :value="values.market" @change="save('market', $event.target.value)">
 *
 * Text fields save on blur or Enter, select lists and switches at once. The view changes before the
 * answer and rolls back on an error, which is shown at the field.
 */
export function useAutosave(url, { method = 'patch', only = [], values, request = inertiaRequest } = {}) {
    const autosave = createAutosave({ url, method, only, values: values ?? reactive({}), request });
    if (getCurrentInstance()) {
        onBeforeUnmount(autosave.dispose);
    }

    return autosave;
}
