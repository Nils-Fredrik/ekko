<?php

use App\Detection\DomainOwnership;
use App\Detection\UrlNormalizer;

it('normalizes citation URLs', function (string $input, ?string $url, ?string $domain) {
    $result = (new UrlNormalizer)->normalize($input);

    expect($result['url'] ?? null)->toBe($url)->and($result['domain'] ?? null)->toBe($domain);
})->with([
    'tracking params' => ['https://example.com/a?utm_source=x&b=1&UTM_Medium=y&gclid=1&fbclid=2&srsltid=3&c=4', 'https://example.com/a?b=1&c=4', 'example.com'],
    'only tracking params' => ['https://example.com/a?utm_source=x&gclid=1', 'https://example.com/a', 'example.com'],
    'trailing slash' => ['https://example.com/a/b/', 'https://example.com/a/b', 'example.com'],
    'root slash' => ['https://example.com/', 'https://example.com', 'example.com'],
    'www' => ['https://www.example.com/a', 'https://example.com/a', 'example.com'],
    'host case and default port' => ['HTTPS://WWW.Example.COM:443/Path', 'https://example.com/Path', 'example.com'],
    'custom port kept' => ['http://example.com:8080/a', 'http://example.com:8080/a', 'example.com'],
    'fragment dropped' => ['https://example.com/a#:~:text=foo', 'https://example.com/a', 'example.com'],
    'subdomain kept' => ['https://shop.example.com/', 'https://shop.example.com', 'shop.example.com'],
    'no scheme' => ['example.com/a/', 'https://example.com/a', 'example.com'],
    'scheme-relative' => ['//example.com/a', 'https://example.com/a', 'example.com'],
    'similar param names kept' => ['https://example.com/?utmx=1&my_gclid=2', 'https://example.com?utmx=1&my_gclid=2', 'example.com'],
    'idn host' => ['https://bjørnkaffe.no/', 'https://xn--bjrnkaffe-m8a.no', 'xn--bjrnkaffe-m8a.no'],
    'javascript' => ['javascript:alert(1)', null, null],
    'data' => ['data:text/html,x', null, null],
    'ftp' => ['ftp://example.com/a', null, null],
    'no host' => ['https:///a', null, null],
    'single label host' => ['https://intranet/a', null, null],
    'whitespace' => ['https://exa mple.com', null, null],
    'empty' => ['', null, null],
]);

it('decides ownership from brand and competitor domains', function () {
    $ownership = new DomainOwnership(
        [
            ['domain' => 'fjordkaffe.no', 'include_subdomains' => true],
            ['domain' => 'https://www.fjordkaffe.se/', 'include_subdomains' => false],
        ],
        [
            ['domain' => 'rival.no', 'competitor_id' => 1],
            ['domain' => 'shop.rival.no', 'competitor_id' => 2],
            ['domain' => 'fjordkaffe.no.example.com', 'competitor_id' => 3],
        ],
    );

    expect($ownership->classify('fjordkaffe.no'))->toBe(['ownership' => 'own', 'competitor_id' => null])
        ->and($ownership->classify('blogg.fjordkaffe.no')['ownership'])->toBe('own')
        ->and($ownership->classify('fjordkaffe.se')['ownership'])->toBe('own')
        ->and($ownership->classify('blogg.fjordkaffe.se')['ownership'])->toBe('third_party')
        ->and($ownership->classify('rival.no'))->toBe(['ownership' => 'competitor', 'competitor_id' => 1])
        ->and($ownership->classify('x.rival.no')['competitor_id'])->toBe(1)
        ->and($ownership->classify('shop.rival.no')['competitor_id'])->toBe(2)
        ->and($ownership->classify('notrival.no')['ownership'])->toBe('third_party')
        ->and($ownership->classify('fjordkaffe.no.example.com')['ownership'])->toBe('competitor')
        ->and($ownership->classify('myfjordkaffe.no')['ownership'])->toBe('third_party');
});

it('lets a brand domain win over the same competitor domain', function () {
    $ownership = new DomainOwnership(
        [['domain' => 'same.no', 'include_subdomains' => false]],
        [['domain' => 'same.no', 'competitor_id' => 9]],
    );

    expect($ownership->classify('same.no')['ownership'])->toBe('own');
});
