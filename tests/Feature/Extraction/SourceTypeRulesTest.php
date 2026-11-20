<?php

// The deterministic rules for a citation's source_type (T20-F2, ADR 0011 point 10).

use App\Extraction\ExtractionPromptBuilder;
use App\Extraction\ExtractionSchema;
use App\Extraction\SourceTypeRules;

it('stores other for own and competitor sites, whatever the model says', function (string $ownership) {
    expect((new SourceTypeRules)->resolve('shop.fjordkaffe.no', $ownership, 'marketplace'))->toBe('other')
        ->and((new SourceTypeRules)->resolve('reddit.com', $ownership, 'forum'))->toBe('other');
})->with(['own', 'competitor']);

it('gives well-known hosts and their subdomains a fixed type over the model', function (string $domain, string $type) {
    expect((new SourceTypeRules)->resolve($domain, 'third_party', 'other'))->toBe($type)
        ->and((new SourceTypeRules)->resolve($domain, 'third_party', null))->toBe($type);
})->with([
    ['reddit.com', 'forum'],
    ['old.reddit.com', 'forum'],
    ['flashback.org', 'forum'],
    ['no.wikipedia.org', 'wiki'],
    ['snl.no', 'wiki'],
    ['se.trustpilot.com', 'review'],
    ['instagram.com', 'social'],
    ['finn.no', 'marketplace'],
    ['prisjakt.nu', 'marketplace'],
    ['oslo.kommune.no', 'gov'],
    ['livsmedelsverket.se', 'gov'],
    ['data.gov', 'gov'],
    ['vg.no', 'news'],
    ['svt.se', 'news'],
    ['WWW.Dagbladet.no', 'news'],
]);

it('keeps the model type for other hosts and does not match on a suffix of a label', function () {
    $rules = new SourceTypeRules;

    expect($rules->resolve('kaffeprat.blogspot.com', 'third_party', 'other'))->toBe('other')
        ->and($rules->resolve('example.org', 'third_party', 'review'))->toBe('review')
        ->and($rules->resolve('example.org', 'third_party', null))->toBeNull()
        ->and($rules->resolve('notreddit.com', 'third_party', 'news'))->toBe('news')
        ->and($rules->resolve('avg.no', 'third_party', 'other'))->toBe('other');
});

it('defines every source type for the model, with the own-site rule', function () {
    foreach (ExtractionSchema::SOURCE_TYPES as $type) {
        expect(ExtractionPromptBuilder::SYSTEM_PROMPT)->toContain("  - {$type}: ");
    }
    expect(ExtractionPromptBuilder::SYSTEM_PROMPT)->toContain('The website of a single company or brand is always other')
        ->toContain('Reddit is a forum, not social');
});
