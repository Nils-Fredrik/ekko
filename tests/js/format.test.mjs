import assert from 'node:assert/strict';
import test from 'node:test';
import {
    compareText,
    configureFormat,
    formatCount,
    formatCurrency,
    formatDate,
    formatDateTime,
    formatDecimal,
    formatNumber,
    formatPercent,
    formatPoints,
    formatRelative,
    formatTime,
} from '../../resources/js/format.js';
import { useLanguage } from './support/i18n.mjs';

// Intl uses no-break and narrow no-break spaces; compare with plain spaces.
const plain = (value) => value.replace(/[  ]/g, ' ');
const now = new Date('2026-11-21T10:00:00Z');

function inLocale(locale, timeZone = 'Europe/Oslo') {
    useLanguage(locale);
    configureFormat({ locale, timeZone });
}

const expected = {
    nb: {
        number: '1 412',
        negative: '−1 412',
        decimal: '2,6',
        negativeDecimal: '−2,6',
        currency: '16,54 USD',
        unitPrice: '0,0123 USD',
        negativeCurrency: '−16,54 USD',
        percent: '38 %',
        negativePercent: '−38 %',
        pointsDown: '−3,1 pp',
        pointsUp: '+2,5 pp',
        date: '1. nov.',
        dateOtherYear: '1. nov. 2025',
        dateTime: '21. nov. 02:14',
        tonight: 'i natt',
        yesterday: 'i går',
        today: 'i dag',
        tomorrow: 'i morgen',
        inDays: 'om 3 dager',
        daysAgo: 'for 5 dager siden',
        oneAnswer: '1 svar',
        manyAnswers: '1 412 svar',
    },
    sv: {
        number: '1 412',
        negative: '−1 412',
        decimal: '2,6',
        negativeDecimal: '−2,6',
        currency: '16,54 USD',
        unitPrice: '0,0123 USD',
        negativeCurrency: '−16,54 USD',
        percent: '38 %',
        negativePercent: '−38 %',
        pointsDown: '−3,1 p.e.',
        pointsUp: '+2,5 p.e.',
        date: '1 nov.',
        dateOtherYear: '1 nov. 2025',
        dateTime: '21 nov. 02:14',
        tonight: 'i natt',
        yesterday: 'i går',
        today: 'i dag',
        tomorrow: 'i morgon',
        inDays: 'om 3 dagar',
        daysAgo: 'för 5 dagar sedan',
        oneAnswer: '1 svar',
        manyAnswers: '1 412 svar',
    },
    en: {
        number: '1,412',
        negative: '−1,412',
        decimal: '2.6',
        negativeDecimal: '−2.6',
        currency: 'USD 16.54',
        unitPrice: 'USD 0.0123',
        negativeCurrency: '−USD 16.54',
        percent: '38%',
        negativePercent: '−38%',
        pointsDown: '−3.1 pp',
        pointsUp: '+2.5 pp',
        date: '1 Nov',
        dateOtherYear: '1 Nov 2025',
        dateTime: '21 Nov 02:14',
        tonight: 'last night',
        yesterday: 'yesterday',
        today: 'today',
        tomorrow: 'tomorrow',
        inDays: 'in 3 days',
        daysAgo: '5 days ago',
        oneAnswer: '1 answer',
        manyAnswers: '1,412 answers',
    },
};

for (const [locale, want] of Object.entries(expected)) {
    test(`${locale}: numbers, money and shares`, () => {
        inLocale(locale);
        assert.equal(plain(formatNumber(1412)), want.number);
        assert.equal(plain(formatNumber(-1412)), want.negative);
        assert.equal(plain(formatDecimal(2.64, 1)), want.decimal);
        assert.equal(plain(formatDecimal(-2.64, 1)), want.negativeDecimal);
        assert.equal(plain(formatCurrency(16.5374371, 'USD')), want.currency);
        assert.equal(plain(formatCurrency('16.53743710', 'USD')), want.currency);
        assert.equal(plain(formatCurrency(0.0123456, 'USD', { unit: true })), want.unitPrice);
        assert.equal(plain(formatCurrency(-16.5374371, 'USD')), want.negativeCurrency);
        assert.equal(plain(formatPercent(0.3812)), want.percent);
        assert.equal(plain(formatPercent(-0.3812)), want.negativePercent);
        assert.equal(plain(formatPoints(-0.031)), want.pointsDown);
        assert.equal(plain(formatPoints(0.025)), want.pointsUp);
    });

    test(`${locale}: dates in the tenant's time zone`, () => {
        inLocale(locale);
        assert.equal(plain(formatDate('2026-11-01', { now })), want.date);
        assert.equal(plain(formatDate('2025-11-01', { now })), want.dateOtherYear);
        // 01:14 UTC is 02:14 in Oslo.
        assert.equal(plain(formatDateTime('2026-11-21T01:14:00Z', { now })), want.dateTime);
        assert.equal(formatTime('2026-11-21T01:14:00Z'), '02:14');
    });

    test(`${locale}: relative days and counts from the language file`, () => {
        inLocale(locale);
        assert.equal(formatRelative('2026-11-21T01:14:00Z', { now }), want.tonight);
        assert.equal(formatRelative('2026-11-20T12:00:00Z', { now }), want.yesterday);
        assert.equal(formatRelative('2026-11-21T12:00:00Z', { now }), want.today);
        assert.equal(formatRelative('2026-11-22', { now }), want.tomorrow);
        assert.equal(formatRelative('2026-11-24', { now }), want.inDays);
        assert.equal(formatRelative('2026-11-16T08:00:00Z', { now }), want.daysAgo);
        assert.equal(plain(formatCount(1, 'answers')), want.oneAnswer);
        assert.equal(plain(formatCount(1412, 'answers')), want.manyAnswers);
    });
}

test('missing values show an en dash', () => {
    inLocale('nb');
    for (const value of [null, undefined, '', Number.NaN, 'abc']) {
        assert.equal(formatNumber(value), '–');
        assert.equal(formatPercent(value), '–');
        assert.equal(formatPoints(value), '–');
        assert.equal(formatCurrency(value), '–');
    }
    assert.equal(formatDate(null), '–');
    assert.equal(formatDateTime('not a date'), '–');
    assert.equal(formatRelative(undefined), '–');
});

test('the tenant time zone decides the calendar day and the hour', () => {
    inLocale('nb', 'America/New_York');
    // 03:30 UTC on the 21st is 22:30 on the 20th in New York: yesterday, not tonight.
    assert.equal(formatRelative('2026-11-21T03:30:00Z', { now: new Date('2026-11-21T15:00:00Z') }), 'i går');
    assert.equal(plain(formatDateTime('2026-11-21T03:30:00Z', { now })), '20. nov. 22:30');
    // A calendar date has no time zone.
    assert.equal(plain(formatDate('2026-11-21', { now })), '21. nov.');
    configureFormat({ timeZone: 'Not/AZone' });
    assert.equal(plain(formatDateTime('2026-11-21T03:30:00Z', { now })), '20. nov. 22:30');
    configureFormat({ timeZone: null });
    assert.equal(plain(formatDateTime('2026-11-21T03:30:00Z', { now })), '21. nov. 04:30');
});

test('the year is shown outside the current year, and a whole year can be forced', () => {
    inLocale('nb');
    assert.equal(plain(formatDate('2027-01-02', { now })), '2. jan. 2027');
    assert.equal(plain(formatDate('2026-01-02', { now, year: true })), '2. jan. 2026');
    assert.equal(plain(formatDateTime('2025-12-31T23:30:00Z', { now })), '1. jan. 00:30');
});

test('texts sort the way the language does', () => {
    inLocale('nb');
    assert.deepEqual(['Å', 'Ø', 'Æ', 'B'].sort(compareText), ['B', 'Æ', 'Ø', 'Å']);
});
