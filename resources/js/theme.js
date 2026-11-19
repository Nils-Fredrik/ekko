/**
 * Theme: "dark", "light" or "system". The choice is stored in localStorage under
 * ekko.theme and applied as data-theme on <html> before the first paint
 * (resources/views/app.blade.php), so a light theme does not flash dark.
 */
const STORAGE_KEY = 'ekko.theme';

const media = () => (window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null);

export function currentTheme() {
    try {
        return localStorage.getItem(STORAGE_KEY) || 'dark';
    } catch {
        return 'dark';
    }
}

export function resolveTheme(pref) {
    return pref === 'light' || (pref === 'system' && media()?.matches) ? 'light' : 'dark';
}

export function applyTheme(pref) {
    const choice = pref ?? 'dark';
    try {
        localStorage.setItem(STORAGE_KEY, choice);
    } catch {
        // Private mode can refuse storage. The theme still applies for this page.
    }
    document.documentElement.dataset.theme = resolveTheme(choice);
}

// Charts read theme tokens once, when they draw. They subscribe so a later
// switch repaints axis colors instead of leaving the previous theme on the canvas.
export function onThemeChange(callback) {
    const observer = new MutationObserver(callback);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    return () => observer.disconnect();
}

// Follow the system when it changes while "system" is selected.
export function watchSystemTheme(currentPref) {
    media()?.addEventListener?.('change', () => {
        if (currentPref() === 'system') {
            applyTheme('system');
        }
    });
}
