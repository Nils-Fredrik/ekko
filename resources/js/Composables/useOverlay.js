import { onBeforeUnmount, watch } from 'vue';

/**
 * For panels that cover the page (the mobile menu, a bottom sheet): Escape closes
 * them, and the page behind cannot scroll while one is open.
 *
 *   useOverlay(() => open.value, () => { open.value = false; });
 */
export function useOverlay(isOpen, close) {
    const onKey = (event) => {
        if (event.key === 'Escape') {
            close();
        }
    };
    const release = () => {
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = '';
    };

    watch(isOpen, (open) => {
        if (!open) {
            return release();
        }
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
    });
    onBeforeUnmount(release);
}
