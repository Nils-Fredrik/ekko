import assert from 'node:assert/strict';
import test from 'node:test';
import { answersFor, cellError, nextDue, rowChanged, samplePromptId } from '../../resources/js/planMatrix.js';

const estimate = {
    engines: [{ engine_key: 'chatgpt_api', answers: 12 }],
    markets: [
        {
            id: 4,
            engines: [{ engine_key: 'chatgpt_api', answers: 5 }],
            rules: [
                { engine_key: 'chatgpt_api', next_due_on: '2026-12-02', sample_prompt_id: 9, answers: 3 },
                { engine_key: 'chatgpt_api', next_due_on: '2026-11-20', sample_prompt_id: null, answers: 2 },
            ],
        },
    ],
};

test('a market cell reads its own answers, and the standard column ignores the plan total', () => {
    assert.equal(answersFor(estimate, null, 'chatgpt_api'), null);
    assert.equal(answersFor(estimate, 4, 'chatgpt_api'), 5);
    assert.equal(answersFor(estimate, 4, 'missing'), null);
    assert.equal(answersFor(null, 4, 'chatgpt_api'), null);
});

test('the panel uses the earliest next measurement and the example from that same rule', () => {
    assert.equal(nextDue(estimate, 4, 'chatgpt_api'), '2026-11-20');
    assert.equal(samplePromptId(estimate, 4, 'chatgpt_api'), null);

    const paired = structuredClone(estimate);
    paired.markets[0].rules[1].sample_prompt_id = 4;
    assert.equal(nextDue(paired, 4, 'chatgpt_api'), '2026-11-20');
    assert.equal(samplePromptId(paired, 4, 'chatgpt_api'), 4);
});

test('a validation error is shown on the engine whose rule failed', () => {
    const rules = [{ engine_key: 'chatgpt_api' }, { engine_key: 'chatgpt' }];
    const errors = {
        'standard.engines.1.frequency': 'The interval for ChatGPT is more frequent than the package allows.',
        country: 'Pick a country',
        'markets.0.engines.0.frequency': 'Too often for this market',
    };

    assert.equal(
        cellError(errors, rules, 'standard.engines', 'chatgpt'),
        'The interval for ChatGPT is more frequent than the package allows.',
    );
    assert.equal(cellError(errors, rules, 'standard.engines', 'chatgpt_api'), '');
    assert.equal(
        cellError(errors, [{ engine_key: 'chatgpt' }], 'markets.0.engines', 'chatgpt'),
        'Too often for this market',
    );
    assert.equal(
        cellError({ 'standard.engines.0.frequency': ['Capped'] }, rules, 'standard.engines', 'chatgpt_api'),
        'Capped',
    );
});

test('an own choice is changed only when it differs from the brand standard', () => {
    const standard = { frequency: 'weekly', prompt_scope: 'all', repetitions: 1, core_frequency: '' };
    assert.equal(rowChanged(standard, { ...standard }), false);
    assert.equal(rowChanged(standard, { ...standard, frequency: 'monthly' }), true);
});
