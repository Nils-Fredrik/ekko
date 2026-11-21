import assert from 'node:assert/strict';
import test from 'node:test';
import { mergePersistedMarkets, normalizePlan } from '../../resources/js/measurementPlan.js';

const catalog = [
    { engine_key: 'chatgpt_ui', name: 'ChatGPT', selectable: true, has_price: true, max_frequency: 'weekly' },
    { engine_key: 'chatgpt_api', name: 'ChatGPT API', selectable: false, has_price: false, max_frequency: 'off' },
];

test('an off rule for all prompts keeps an active core rule', () => {
    const rules = normalizePlan(
        [
            { engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
            { engine_key: 'chatgpt_ui', prompt_scope: 'core', frequency: 'weekly', repetitions: 2 },
        ],
        catalog,
        3,
    );

    assert.deepEqual(rules, [
        { engine_key: 'chatgpt_ui', prompt_scope: 'core', frequency: 'weekly', repetitions: 2 },
        { engine_key: 'chatgpt_api', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
    ]);
});

test('an active rule for all prompts keeps a separate core rule', () => {
    const rules = normalizePlan(
        [
            { engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'weekly', repetitions: 1 },
            { engine_key: 'chatgpt_ui', prompt_scope: 'core', frequency: 'monthly', repetitions: 1 },
        ],
        catalog,
        1,
    );

    assert.deepEqual(rules, [
        { engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'weekly', repetitions: 1 },
        { engine_key: 'chatgpt_ui', prompt_scope: 'core', frequency: 'monthly', repetitions: 1 },
        { engine_key: 'chatgpt_api', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
    ]);
});

test('saving settings refreshes market activity without dropping an unsaved plan', () => {
    const draftRules = [{ engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'weekly', repetitions: 1 }];
    const current = [
        {
            id: 1,
            country: 'NO',
            language: 'nb',
            location_label: null,
            is_active: false,
            plan_mode: 'own',
            rules: draftRules,
        },
    ];
    const merged = mergePersistedMarkets(
        current,
        [
            {
                id: 1,
                country: 'NO',
                language: 'nb',
                location_label: 'Oslo',
                is_active: true,
                plan_mode: 'inherit',
                engines: [{ engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'monthly', repetitions: 1 }],
            },
        ],
        catalog,
        1,
    );

    assert.equal(merged.activityChanged, true);
    assert.equal(merged.structureChanged, false);
    assert.equal(merged.markets[0].is_active, true);
    assert.equal(merged.markets[0].location_label, 'Oslo');
    assert.equal(merged.markets[0].plan_mode, 'own');
    assert.equal(merged.markets[0].rules, draftRules);
});

test('a new persisted market starts from its saved plan', () => {
    const merged = mergePersistedMarkets(
        [],
        [
            {
                id: 4,
                country: 'SE',
                language: 'sv',
                location_label: null,
                is_active: true,
                plan_mode: 'inherit',
                engines: [],
            },
        ],
        catalog,
        1,
    );

    assert.equal(merged.structureChanged, true);
    assert.equal(merged.markets[0].plan_mode, 'inherit');
    assert.deepEqual(merged.markets[0].rules, [
        { engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
        { engine_key: 'chatgpt_api', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
    ]);
});

test('repetitions above the package cap are clamped and an unavailable engine is off', () => {
    const rules = normalizePlan(
        [{ engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'weekly', repetitions: 3 }],
        catalog,
        1,
    );

    assert.deepEqual(rules, [
        { engine_key: 'chatgpt_ui', prompt_scope: 'all', frequency: 'weekly', repetitions: 1 },
        { engine_key: 'chatgpt_api', prompt_scope: 'all', frequency: 'off', repetitions: 1 },
    ]);
});
