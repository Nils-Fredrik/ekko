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

/** A save that was stopped before it finished (a page change). The server may have it, so nothing rolls back. */
export class SaveCancelled extends Error {
    constructor() {
        super('save cancelled');
    }
}

/**
 * Sends one field with Inertia and resolves when the server accepted it. Validation errors reject
 * with a SaveError, a server or network error with an empty one, and a stopped visit with SaveCancelled.
 *
 * The visit is async: two fields saved at once (blur on a text field, then a click on a switch) must
 * not cancel each other, as two ordinary Inertia visits would.
 */
export function inertiaRequest(method, url, data, { only = [] } = {}, client = router) {
    return new Promise((resolve, reject) => {
        let settled = false;
        client[method](url, data, {
            async: true,
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
            onCancel: () => {
                settled = true;
                reject(new SaveCancelled());
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
 *
 * Saves to one field go one at a time, so the server always gets them in order: while a save is
 * on its way, a newer value waits, and only the newest waiting value is sent next. Different
 * fields save at the same time. A save resolves with the outcome of the last value sent for its
 * field, once nothing more waits.
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
    const timers = {};
    // Per field: the last value the server accepted (a failure rolls back to it), whether a save is
    // on its way, the newest value waiting for it, the callers waiting for the outcome, and a
    // generation that reset() bumps so a save still on its way for an old row changes nothing.
    const confirmed = {};
    const inFlight = {};
    const waiting = {};
    const waiters = {};
    const generation = {};

    function status(field) {
        return statuses[field] ?? 'idle';
    }

    function finish(field, outcome, error) {
        const callers = waiters[field] ?? [];
        delete waiters[field];
        if (outcome === 'saved') {
            statuses[field] = 'saved';
            const gen = generation[field];
            timers[field] = setTimer(() => {
                if (generation[field] === gen && statuses[field] === 'saved') {
                    statuses[field] = 'idle';
                }
            }, SAVED_VISIBLE_MS);
        } else if (outcome === 'cancelled') {
            // Unknown on the server: keep what the user chose and show no error.
            statuses[field] = 'idle';
        } else {
            values[field] = confirmed[field];
            statuses[field] = 'error';
            const messages = error instanceof SaveError ? error.errors : {};
            const message = messages[field] ?? Object.values(messages)[0];
            errors[field] = Array.isArray(message) ? message[0] : (message ?? trans('workspace.save.failed'));
        }
        callers.forEach((resolve) => resolve(outcome === 'saved'));
    }

    async function send(field, value) {
        const gen = generation[field];
        inFlight[field] = true;
        let outcome = 'saved';
        let failure = null;
        try {
            await request(method, toValue(url), { [field]: value }, { only });
            if (generation[field] === gen) {
                confirmed[field] = value;
            }
        } catch (error) {
            outcome = error instanceof SaveCancelled ? 'cancelled' : 'failed';
            failure = error;
        }
        inFlight[field] = false;
        // A waiting value is always the newest, also for a new row after reset(): send it.
        if (field in waiting) {
            const next = waiting[field];
            delete waiting[field];
            send(field, next);

            return;
        }
        if (generation[field] === gen) {
            finish(field, outcome, failure);
        }
    }

    function save(field, value) {
        if (Object.is(values[field], value) && status(field) !== 'error') {
            return Promise.resolve(true);
        }
        generation[field] ??= 0;
        if (!(field in confirmed)) {
            confirmed[field] = values[field];
        }
        clearTimer(timers[field]);
        values[field] = value;
        statuses[field] = 'saving';
        delete errors[field];
        const outcome = new Promise((resolve) => {
            (waiters[field] ??= []).push(resolve);
        });
        if (inFlight[field]) {
            waiting[field] = value;
        } else {
            send(field, value);
        }

        return outcome;
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
        const fields = new Set([...Object.keys(statuses), ...Object.keys(confirmed), ...Object.keys(waiters)]);
        for (const field of fields) {
            clearTimer(timers[field]);
            generation[field] = (generation[field] ?? 0) + 1;
            (waiters[field] ?? []).forEach((resolve) => resolve(false));
            delete waiters[field];
            delete waiting[field];
            delete confirmed[field];
            delete statuses[field];
            delete errors[field];
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
