<?php

use App\Billing\BillingTotals;

test('billing reconciles all usage but invoices platform costs with exact decimal markup', function () {
    $events = [
        ['engine_key' => 'claude_haiku', 'calls' => 3, 'cost_usd' => '0.10000001', 'billed_to' => 'platform'],
        ['engine_key' => 'claude_haiku', 'calls' => 2, 'cost_usd' => '0.20000002', 'billed_to' => 'platform'],
        ['engine_key' => 'chatgpt_api', 'calls' => 1, 'cost_usd' => '99.00000000', 'billed_to' => 'byok'],
        ['engine_key' => null, 'calls' => 1, 'cost_usd' => '0.01000000', 'billed_to' => 'platform'],
    ];
    expect(BillingTotals::summarize($events, '25'))->toBe([
        'calls' => 7,
        'platform_cost_usd' => '0.31000003',
        'byok_cost_usd' => '99.00000000',
        'engine_costs_usd' => ['claude_haiku' => '0.30000003', 'other' => '0.01000000'],
        'markup_percent' => '25',
        'markup_usd' => '0.07750001',
        'invoice_total_usd' => '0.39',
    ]);
});

test('missing markup remains incomplete while explicit zero is a valid configured value', function () {
    expect(BillingTotals::summarize([], null)['invoice_total_usd'])->toBeNull();
    expect(BillingTotals::summarize([], '0')['invoice_total_usd'])->toBe('0.00');
    expect(fn () => BillingTotals::summarize([], '-1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => BillingTotals::summarize([['engine_key' => null, 'calls' => 1, 'cost_usd' => '1', 'billed_to' => 'invalid']], '0'))->toThrow(InvalidArgumentException::class);
});

test('invoice rounding happens once after exact markup calculation', function () {
    $events = [['engine_key' => 'test', 'calls' => 1, 'cost_usd' => '0.00499999', 'billed_to' => 'platform']];
    expect(BillingTotals::summarize($events, '0.00012')['invoice_total_usd'])->toBe('0.00');
});
