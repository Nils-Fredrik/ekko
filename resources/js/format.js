// The only place numbers, money, shares and dates are formatted (docs/ux-arbeidsflate.md 4.2).
// ESLint stops toFixed(, toLocaleString( and Intl. everywhere else in resources/js.
//
// The locale is the shared Inertia prop `locale` and the time zone the tenant's (`tenant.timezone`).
// app.js keeps both in step with every page visit through configureFormat(). The settings are
// reactive, so computed values that format something follow a language switch.
import { trans, transChoice } from 'laravel-vue-i18n';
import { reactive } from 'vue';

export const INTL_LOCALES = { nb: 'nb-NO', sv: 'sv-SE', en: 'en-GB' };
export const DEFAULT_TIME_ZONE = 'Europe/Oslo';

const MINUS = '−';
const DAY = 86_400_000;

const settings = reactive({ locale: 'nb', timeZone: DEFAULT_TIME_ZONE });
const cache = new Map();

/** Sets the language and the tenant's time zone. Unknown values keep the defaults. */
export function configureFormat({ locale, timeZone } = {}) {
    if (typeof locale === 'string' && locale !== '') {
        settings.locale = locale;
    }
    if (typeof timeZone === 'string' && timeZone !== '' && validTimeZone(timeZone)) {
        settings.timeZone = timeZone;
    } else if (timeZone === null) {
        settings.timeZone = DEFAULT_TIME_ZONE;
    }
}

export function currentFormatSettings() {
    return { locale: settings.locale, timeZone: settings.timeZone };
}

export function intlLocale(locale = settings.locale) {
    return INTL_LOCALES[locale] ?? INTL_LOCALES.nb;
}

function validTimeZone(timeZone) {
    try {
        formatter('DateTimeFormat', 'en-GB', { timeZone });
        return true;
    } catch {
        return false;
    }
}

function formatter(kind, locale, options) {
    const key = `${kind}|${locale}|${JSON.stringify(options)}`;
    let instance = cache.get(key);
    if (!instance) {
        instance = new Intl[kind](locale, options);
        cache.set(key, instance);
    }

    return instance;
}

function isMissing(value) {
    return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
}

/** The text shown for a missing value: an en dash. */
export function empty() {
    return '–';
}

function number(value, options) {
    // A real minus sign in every language (nb already uses one).
    return formatter('NumberFormat', intlLocale(), options).format(Number(value)).replace(/^-/, MINUS);
}

/** Whole number with group separators: 1 412. Extra Intl options, such as maximumFractionDigits, are allowed. */
export function formatNumber(value, options = {}) {
    if (isMissing(value)) {
        return empty();
    }

    return number(value, { maximumFractionDigits: 0, ...options });
}

/** A fixed number of decimals: formatDecimal(2.64, 1) is 2,6. */
export function formatDecimal(value, digits = 1, options = {}) {
    if (isMissing(value)) {
        return empty();
    }

    return number(value, { minimumFractionDigits: digits, maximumFractionDigits: digits, ...options });
}

/** Money with the currency code: 16,54 USD. `{ unit: true }` gives four decimals for unit prices. */
export function formatCurrency(value, currency = 'USD', { unit = false, digits } = {}) {
    if (isMissing(value)) {
        return empty();
    }
    const decimals = digits ?? (unit ? 4 : 2);

    return number(value, {
        style: 'currency',
        currency,
        currencyDisplay: 'code',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

/** A share from 0 to 1 as percent, without decimals by default: 38 %. */
export function formatPercent(value, options = {}) {
    if (isMissing(value)) {
        return empty();
    }

    return number(value, { style: 'percent', maximumFractionDigits: 0, ...options });
}

/** A change in a share (0 to 1) as percentage points, always signed: −3,1 pp. */
export function formatPoints(change, { digits = 1 } = {}) {
    if (isMissing(change)) {
        return empty();
    }
    const value = number(Number(change) * 100, {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
        signDisplay: 'exceptZero',
    });

    return trans('workspace.format.points', { value });
}

/** A whole number with a translated noun: 1 svar, 412 svar. The key is under workspace.format.count. */
export function formatCount(n, key) {
    const count = Number.isFinite(Number(n)) ? Number(n) : 0;

    return transChoice(`workspace.format.count.${key}`, count, { n: formatNumber(count) });
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/** A Date, plus whether it is a calendar date (no time of day, no time zone). */
function toDate(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : { date: value, calendar: false };
    }
    if (typeof value === 'string' && DATE_ONLY.test(value)) {
        const [year, month, day] = value.split('-').map(Number);

        return { date: new Date(Date.UTC(year, month - 1, day)), calendar: true };
    }
    if (typeof value === 'string' || typeof value === 'number') {
        const date = new Date(value);

        return Number.isNaN(date.getTime()) ? null : { date, calendar: false };
    }

    return null;
}

/** Year, month, day and hour of an instant in a time zone. */
function parts(date, timeZone) {
    const values = {};
    for (const part of formatter('DateTimeFormat', 'en-GB', {
        timeZone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        hourCycle: 'h23',
    }).formatToParts(date)) {
        values[part.type] = Number(part.value);
    }

    return values;
}

function zoneOf(parsed) {
    return parsed.calendar ? 'UTC' : settings.timeZone;
}

/** 1. nov. 2026; the year is left out in the current year (in the tenant's time zone). A year-month is the month name. */
export function formatDate(value, { now = new Date(), year } = {}) {
    const monthOnly = typeof value === 'string' ? /^(\d{4})-(\d{2})$/.exec(value) : null;
    if (monthOnly) {
        // `year: false` gives the month alone, as in «Forbruk november» in the sidebar.
        return formatter('DateTimeFormat', intlLocale(), {
            month: 'long',
            ...(year === false ? {} : { year: 'numeric' }),
            timeZone: 'UTC',
        }).format(new Date(Date.UTC(Number(monthOnly[1]), Number(monthOnly[2]) - 1, 1)));
    }
    const parsed = toDate(value);
    if (!parsed) {
        return empty();
    }
    const timeZone = zoneOf(parsed);
    const showYear = year ?? parts(parsed.date, timeZone).year !== parts(now, settings.timeZone).year;

    return formatter('DateTimeFormat', intlLocale(), {
        timeZone,
        day: 'numeric',
        month: 'short',
        ...(showYear ? { year: 'numeric' } : {}),
    }).format(parsed.date);
}

/** 21. nov. 02:14 in the tenant's time zone; the year is added outside the current year. */
export function formatDateTime(value, { now = new Date() } = {}) {
    const parsed = toDate(value);
    if (!parsed) {
        return empty();
    }
    if (parsed.calendar) {
        return formatDate(value, { now });
    }
    const time = formatter('DateTimeFormat', intlLocale(), {
        timeZone: settings.timeZone,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(parsed.date);

    return `${formatDate(parsed.date, { now })} ${time}`;
}

/** Only the time of day in the tenant's time zone: 02:14. */
export function formatTime(value) {
    const parsed = toDate(value);
    if (!parsed || parsed.calendar) {
        return empty();
    }

    return formatter('DateTimeFormat', intlLocale(), {
        timeZone: settings.timeZone,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(parsed.date);
}

/**
 * Days relative to today in the tenant's time zone: i natt, i går, i dag, om 3 dager.
 * «I natt» is a time between 00:00 and 06:00 today. More than 30 days away shows the date.
 */
export function formatRelative(value, { now = new Date() } = {}) {
    const parsed = toDate(value);
    if (!parsed) {
        return empty();
    }
    const then = parts(parsed.date, zoneOf(parsed));
    const today = parts(now, settings.timeZone);
    const days = Math.round(
        (Date.UTC(then.year, then.month - 1, then.day) - Date.UTC(today.year, today.month - 1, today.day)) / DAY,
    );
    if (days === 0 && !parsed.calendar && then.hour < 6) {
        return trans('workspace.format.tonight');
    }
    if (Math.abs(days) > 30) {
        return formatDate(value, { now });
    }
    // The language files, not Intl.RelativeTimeFormat: ICU says «om 3 døgn» in Norwegian.
    const named = { '-1': 'yesterday', 0: 'today', 1: 'tomorrow' }[days];
    if (named) {
        return trans(`workspace.format.${named}`);
    }
    const count = Math.abs(days);

    return transChoice(`workspace.format.${days > 0 ? 'in_days' : 'days_ago'}`, count, {
        n: formatNumber(count),
    });
}

/** Compares two texts the way the language sorts them (æ, ø and å last in Norwegian). */
export function compareText(a, b) {
    return formatter('Collator', intlLocale(), {}).compare(String(a ?? ''), String(b ?? ''));
}
