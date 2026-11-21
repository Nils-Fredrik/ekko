import { trans } from 'laravel-vue-i18n';
import { shallowRef } from 'vue';

/** How long the message stays. */
export const UNDO_VISIBLE_MS = 8000;

/**
 * One message at a time with «Angre» (docs/ux-arbeidsflate.md 4.5). Undo calls the opposite action
 * on the server; nothing is deleted later. A failed undo replaces the message with the error.
 */
export function createUndoToast({
    setTimer = (callback, ms) => setTimeout(callback, ms),
    clearTimer = (timer) => clearTimeout(timer),
} = {}) {
    const current = shallowRef(null);
    let timer = null;
    let sequence = 0;

    function dismiss() {
        clearTimer(timer);
        timer = null;
        current.value = null;
    }

    function show(text, undoFn = null) {
        clearTimer(timer);
        const id = ++sequence;
        current.value = { id, text, undo: typeof undoFn === 'function' ? undoFn : null, busy: false };
        timer = setTimer(() => {
            if (current.value?.id === id) {
                dismiss();
            }
        }, UNDO_VISIBLE_MS);

        return id;
    }

    async function undo() {
        const toast = current.value;
        if (!toast?.undo || toast.busy) {
            return false;
        }
        current.value = { ...toast, busy: true };
        try {
            await toast.undo();
            if (current.value?.id === toast.id) {
                dismiss();
            }

            return true;
        } catch {
            if (current.value?.id === toast.id) {
                show(trans('workspace.undo.failed'));
            }

            return false;
        }
    }

    return { current, show, undo, dismiss };
}

// The app has one message area (UndoToast.vue in the layout), so every page shares this instance.
const shared = createUndoToast();

export function useUndoToast() {
    return shared;
}
