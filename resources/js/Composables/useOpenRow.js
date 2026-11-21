import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The open row from a URL: `?open=12` gives 12, `?open=abc` gives 'abc', no parameter gives null. */
export function openIdFromUrl(url, param = 'open') {
    const value = new URL(url, 'http://ekko.invalid').searchParams.get(param);
    if (value === null || value === '') {
        return null;
    }

    return /^\d+$/.test(value) ? Number(value) : value;
}

/** The same URL with the parameter set, or removed for null. Other parameters stay. */
export function urlWithOpen(url, id, param = 'open') {
    const target = new URL(url, 'http://ekko.invalid');
    if (id === null || id === undefined) {
        target.searchParams.delete(param);
    } else {
        target.searchParams.set(param, String(id));
    }

    return `${target.pathname}${target.search}${target.hash}`;
}

/**
 * The row open in the detail panel lives in the URL as `?open=<id>` (docs/ux-arbeidsflate.md 4.4).
 * Opening adds a history entry, so Back closes the panel. Moving to another row (j, k) and closing
 * replace the entry. These are client-side Inertia visits: the server is not asked again.
 */
export function useOpenRow(param = 'open') {
    const page = usePage();
    const openId = computed(() => openIdFromUrl(page.url, param));

    function visit(id, replace) {
        const options = { url: urlWithOpen(page.url, id, param), preserveState: true, preserveScroll: true };
        if (replace) {
            router.replace(options);
        } else {
            router.push(options);
        }
    }

    return {
        openId,
        open: (id) => {
            if (id === openId.value) {
                return;
            }
            visit(id, openId.value !== null);
        },
        close: () => {
            if (openId.value !== null) {
                visit(null, true);
            }
        },
    };
}
