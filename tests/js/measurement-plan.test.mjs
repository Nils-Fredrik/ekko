import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizePlan } from '../../resources/js/measurementPlan.js';

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
