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
export function inertiaRequest(method, url, data, options = {}, client = router) {
    return new Promise((resolve, reject) => {
        let settled = false;
        client[method](url, data, {
            async: true,
            preserveState: true,
            preserveScroll: true,
            only: [],
            ...options,
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
 * Saves to one field of one row go one at a time, so the server always gets them in order: while
 * a save is on its way, a newer value waits, and only the newest waiting value is sent next. Each
 * row and field (URL and field) has its own lane, so different fields and rows save at the same
 * time. A save resolves with the outcome of the last value sent for its field, once nothing more
 * waits. reset() (another row in the panel) detaches the screen from the old row: its waiting
 * values are still sent to the old row's URL, in order, and only change the view again if that row
 * is open when they succeed.
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
    // Per field on screen: the last value the server accepted (a failure rolls back to it), the
    // callers waiting for the outcome, and a generation that reset() bumps.
    const confirmed = {};
    const waiters = {};
    const generation = {};
    // Per URL and field: whether a save is on its way, and the newest value waiting for it.
    const lanes = new Map();

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

    async function send(key, lane, field, value, gen) {
        lane.inFlight = true;
        let outcome = 'saved';
        let failure = null;
        try {
            await request(method, lane.url, { [field]: value }, { only });
            if (generation[field] === gen) {
                confirmed[field] = value;
            } else if (lane.url === toValue(url)) {
                // The row was closed and opened again while this save was on its way: the server now
                // has this value. It is what a later failure rolls back to, and it is shown unless the
                // user is already saving a newer one.
                confirmed[field] = value;
                if (status(field) !== 'saving') {
                    values[field] = value;
                }
            }
        } catch (error) {
            outcome = error instanceof SaveCancelled ? 'cancelled' : 'failed';
            failure = error;
        }
        lane.inFlight = false;
        if (lane.waiting) {
            const next = lane.waiting;
            lane.waiting = null;
            send(key, lane, field, next.value, next.gen);

            return;
        }
        lanes.delete(key);
        if (generation[field] === gen) {
            finish(field, outcome, failure);
        }
    }

    function save(field, value) {
        if (Object.is(values[field], value) && status(field) !== 'error') {
            return Promise.resolve(true);
        }
        generation[field] ??= 0;
        const gen = generation[field];
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
        const target = toValue(url);
        const key = `${target}\u0000${field}`;
        let lane = lanes.get(key);
        if (!lane) {
            lane = { url: target, inFlight: false, waiting: null };
            lanes.set(key, lane);
        }
        if (lane.inFlight) {
            lane.waiting = { value, gen };
        } else {
            send(key, lane, field, value, gen);
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

    /**
     * Clears the statuses when the panel shows another row. Saves still on their way or waiting for
     * the old row are sent as before; they just no longer change the view.
     */
    function reset() {
        const fields = new Set([...Object.keys(statuses), ...Object.keys(confirmed), ...Object.keys(waiters)]);
        for (const field of fields) {
            clearTimer(timers[field]);
            generation[field] = (generation[field] ?? 0) + 1;
            (waiters[field] ?? []).forEach((resolve) => resolve(false));
            delete waiters[field];
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
