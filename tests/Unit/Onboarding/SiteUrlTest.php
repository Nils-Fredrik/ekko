<?php

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\SiteUrl;
use App\Onboarding\Fetch\UrlResolver;

it('takes a bare domain or a pasted address and returns the host', function (string $input, string $host) {
    expect(SiteUrl::hostFromUserInput($input))->toBe($host);
})->with([
    ['eksempel.no', 'eksempel.no'],
    ['  Eksempel.NO ', 'eksempel.no'],
    ['https://www.eksempel.no/produkter?x=1#y', 'www.eksempel.no'],
    ['http://eksempel.no', 'eksempel.no'],
    ['eksempel.no.', 'eksempel.no'],
    ['bokmål.no', 'xn--bokml-pra.no'],
    ['https://eksempel.no:443/', 'eksempel.no'],
]);

it('refuses what is not a public host name', function (string $input) {
    expect(SiteUrl::hostFromUserInput($input))->toBeNull();
})->with([
    'empty' => '',
    'localhost' => 'localhost',
    'localhost with port' => 'localhost:8000',
    'dot localhost' => 'app.localhost',
    'local suffix' => 'printer.local',
    'internal suffix' => 'metadata.google.internal',
    'no dot' => 'intranet',
    'IPv4' => '127.0.0.1',
    'IPv4 metadata' => '169.254.169.254',
    'IPv4 url' => 'http://10.0.0.1/admin',
    'decimal IPv4' => '2130706433',
    'decimal IPv4 as url' => 'http://2130706433/',
    'hex IPv4' => '0x7f000001',
    'dotted hex' => '0x7f.0.0.1',
    'short IPv4' => '127.1',
    'IPv6' => '[::1]',
    'IPv6 url' => 'https://[::1]/',
    'IPv6 mapped' => 'https://[::ffff:127.0.0.1]/',
    'credentials' => 'https://user:pass@eksempel.no/',
    'credential trick' => 'https://eksempel.no@evil.example/',
    'other port' => 'https://eksempel.no:8443/',
    'file scheme' => 'file:///etc/passwd',
    'gopher' => 'gopher://eksempel.no/',
    'javascript' => 'javascript:alert(1)',
    'whitespace inside' => 'eksempel .no',
    'newline' => "eksempel.no\r\nHost: evil.example",
    'backslash' => 'https://eksempel.no\\@evil.example/',
    'underscore' => 'ekse_mpel.no',
    'leading dash' => '-eksempel.no',
    'label too long' => str_repeat('a', 64).'.no',
]);

it('validates an address that will really be requested', function () {
    expect(SiteUrl::validate('https://Eksempel.no/om-oss?x=1#frag'))->toBe(['url' => 'https://eksempel.no/om-oss?x=1', 'host' => 'eksempel.no'])
        ->and(SiteUrl::validate('https://eksempel.no'))->toBe(['url' => 'https://eksempel.no/', 'host' => 'eksempel.no'])
        ->and(SiteUrl::validate('https://eksempel.no:443/a')['url'])->toBe('https://eksempel.no/a');
});

it('refuses an address that is not strictly https on the default port', function (string $url) {
    SiteUrl::validate($url);
})->with([
    'http' => 'http://eksempel.no/',
    'port' => 'https://eksempel.no:8443/',
    'port 80 over https' => 'https://eksempel.no:80/',
    'credentials' => 'https://a:b@eksempel.no/',
    'user only' => 'https://a@eksempel.no/',
    'ip' => 'https://127.0.0.1/',
    'ip decimal' => 'https://2130706433/',
    'ipv6' => 'https://[::1]/',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://eksempel.no/',
    'no host' => 'https:///path',
    'newline' => "https://eksempel.no/\nX: y",
    'backslash' => 'https://eksempel.no\\.evil.example/',
    'space' => 'https://eksempel.no/a b',
])->throws(FetchFailed::class);

it('refuses an address longer than 2048 characters', function () {
    SiteUrl::validate('https://eksempel.no/'.str_repeat('a', 2100));
})->throws(FetchFailed::class);

it('resolves links against the page they are on', function (string $base, string $reference, ?string $expected) {
    expect(UrlResolver::resolve($base, $reference))->toBe($expected);
})->with([
    'absolute' => ['https://a.no/x/y', 'https://b.no/z', 'https://b.no/z'],
    'protocol relative' => ['https://a.no/x/y', '//b.no/z', 'https://b.no/z'],
    'root relative' => ['https://a.no/x/y', '/z', 'https://a.no/z'],
    'relative' => ['https://a.no/x/y', 'z', 'https://a.no/x/z'],
    'relative in directory' => ['https://a.no/x/y/', 'z', 'https://a.no/x/y/z'],
    'parent' => ['https://a.no/x/y/w', '../z', 'https://a.no/x/z'],
    'above the root' => ['https://a.no/x', '../../../z', 'https://a.no/z'],
    'dot' => ['https://a.no/x/y', './z', 'https://a.no/x/z'],
    'query only' => ['https://a.no/x/y?a=1', '?b=2', 'https://a.no/x/y?b=2'],
    'with query' => ['https://a.no/x/y', 'z?b=2', 'https://a.no/x/z?b=2'],
    'fragment stripped' => ['https://a.no/x', '/z#top', 'https://a.no/z'],
    'fragment only' => ['https://a.no/x', '#top', null],
    'empty' => ['https://a.no/x', '', null],
    'control character' => ['https://a.no/x', "/z\x01", null],
    'mailto is kept for the scheme check' => ['https://a.no/x', 'mailto:a@b.no', 'mailto:a@b.no'],
    'javascript is kept for the scheme check' => ['https://a.no/x', 'javascript:alert(1)', 'javascript:alert(1)'],
]);
