<?php

// docs/spec.md 13 and 16: the front page and up to five central subpages, from the sitemap or the navigation,
// at most six pages, all through the SSRF-safe fetcher.

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\HtmlPage;
use App\Onboarding\Fetch\SafeHttpFetcher;
use App\Onboarding\Fetch\SiteReader;
use App\Onboarding\Fetch\TransportResponse;
use Tests\Support\Onboarding\FakeHostResolver;
use Tests\Support\Onboarding\FakePageTransport;

function reader(array $routes, array $hosts = ['*' => ['93.184.216.34']]): array
{
    $transport = new FakePageTransport($routes);

    return [new SiteReader(new SafeHttpFetcher(new FakeHostResolver($hosts), $transport)), $transport];
}

function page(string $title, string $body = '', string $head = ''): string
{
    return "<html><head><title>{$title}</title>{$head}</head><body>{$body}</body></html>";
}

function sitemap(array $urls): TransportResponse
{
    $locs = implode('', array_map(fn (string $u): string => "<url><loc>{$u}</loc></url>", $urls));

    return FakePageTransport::html('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$locs.'</urlset>', 'application/xml');
}

it('reads the front page and the pages in the navigation when there is no sitemap', function () {
    [$reader, $transport] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/om-oss">Om oss</a><a href="/produkter">Produkter</a></nav><p>Velkommen</p>')),
        'https://eksempel.no/om-oss' => FakePageTransport::html(page('Om oss', '<p>Vi er en skobutikk</p>')),
        'https://eksempel.no/produkter' => FakePageTransport::html(page('Produkter', '<p>Sko</p>')),
    ]);

    $pages = $reader->read('eksempel.no');

    expect(array_map(fn ($p) => $p->url, $pages))->toBe(['https://eksempel.no/', 'https://eksempel.no/om-oss', 'https://eksempel.no/produkter'])
        ->and($pages[1]->title)->toBe('Om oss')
        ->and($pages[1]->text)->toContain('skobutikk');
    // The sitemap was tried and was not there.
    expect($transport->urls())->toContain('https://eksempel.no/sitemap.xml');
});

it('never reads more than six pages, and counts failed attempts too', function () {
    $links = implode('', array_map(fn ($i) => "<a href=\"/side{$i}\">Side {$i}</a>", range(1, 20)));
    $routes = ['https://eksempel.no/' => FakePageTransport::html(page('Forside', "<nav>{$links}</nav>"))];
    foreach (range(1, 20) as $i) {
        $routes["https://eksempel.no/side{$i}"] = FakePageTransport::html(page("Side {$i}"));
    }
    [$reader, $transport] = reader($routes);

    expect($reader->read('eksempel.no'))->toHaveCount(6);
    // Front page, the sitemap attempt (not a page) and five subpages.
    expect($transport->requests)->toHaveCount(7);

    // With every subpage broken, it still stops after five attempts.
    [$reader, $transport] = reader(['https://eksempel.no/' => $routes['https://eksempel.no/']]);
    expect($reader->read('eksempel.no'))->toHaveCount(1)
        ->and($transport->requests)->toHaveCount(1 + 1 + 5);
});

it('prefers the sitemap, and picks pages that describe the business first', function () {
    [$reader] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/nyheter/2024/stort-oppslag">Nyhet</a></nav>')),
        'https://eksempel.no/sitemap.xml' => sitemap([
            'https://eksempel.no/',
            'https://eksempel.no/blogg/2024/01/et-innlegg',
            'https://eksempel.no/produkter',
            'https://eksempel.no/om-oss',
            'https://eksempel.no/kontakt',
            'https://eksempel.no/tjenester',
            'https://eksempel.no/priser',
            'https://eksempel.no/personvern',
        ]),
        'https://eksempel.no/produkter' => FakePageTransport::html(page('Produkter')),
        'https://eksempel.no/om-oss' => FakePageTransport::html(page('Om oss')),
        'https://eksempel.no/kontakt' => FakePageTransport::html(page('Kontakt')),
        'https://eksempel.no/tjenester' => FakePageTransport::html(page('Tjenester')),
        'https://eksempel.no/priser' => FakePageTransport::html(page('Priser')),
    ]);

    $urls = array_map(fn ($p) => $p->url, $reader->read('eksempel.no'));

    expect($urls)->toBe(['https://eksempel.no/', 'https://eksempel.no/produkter', 'https://eksempel.no/om-oss', 'https://eksempel.no/kontakt', 'https://eksempel.no/tjenester', 'https://eksempel.no/priser']);
});

it('fills up from the navigation when the sitemap has too few pages', function () {
    [$reader] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/om-oss">Om oss</a></nav>')),
        'https://eksempel.no/sitemap.xml' => sitemap(['https://eksempel.no/produkter']),
        'https://eksempel.no/produkter' => FakePageTransport::html(page('Produkter')),
        'https://eksempel.no/om-oss' => FakePageTransport::html(page('Om oss')),
    ]);

    expect(array_map(fn ($p) => $p->url, $reader->read('eksempel.no')))->toHaveCount(3);
});

it('leaves out links to other sites, other schemes, files and the front page itself', function () {
    [$reader, $transport] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav>'
            .'<a href="https://annen.no/om-oss">Annet nettsted</a>'
            .'<a href="http://eksempel.no/usikker">http</a>'
            .'<a href="mailto:a@eksempel.no">Post</a>'
            .'<a href="javascript:alert(1)">Js</a>'
            .'<a href="/katalog.pdf">Katalog</a>'
            .'<a href="/bilde.jpg">Bilde</a>'
            .'<a href="https://127.0.0.1/admin">Ip</a>'
            .'<a href="/">Hjem</a>'
            .'<a href="https://user:pw@eksempel.no/x">Innlogging</a>'
            .'<a href="https://eksempel.no:8443/x">Port</a>'
            .'<a href="/ok#del">Ok</a>'
            .'</nav>')),
        'https://eksempel.no/ok' => FakePageTransport::html(page('Ok')),
    ]);

    $urls = array_map(fn ($p) => $p->url, $reader->read('eksempel.no'));

    expect($urls)->toBe(['https://eksempel.no/', 'https://eksempel.no/ok'])
        ->and($transport->urls())->toBe(['https://eksempel.no/', 'https://eksempel.no/sitemap.xml', 'https://eksempel.no/ok']);
});

it('treats the host with and without www as the same site', function () {
    [$reader] = reader([
        'https://eksempel.no/' => FakePageTransport::redirect('https://www.eksempel.no/'),
        'https://www.eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="https://eksempel.no/om-oss">Om oss</a><a href="https://www.eksempel.no/produkter">Produkter</a></nav>')),
        'https://eksempel.no/om-oss' => FakePageTransport::html(page('Om oss')),
        'https://www.eksempel.no/produkter' => FakePageTransport::html(page('Produkter')),
    ]);

    $urls = array_map(fn ($p) => $p->url, $reader->read('eksempel.no'));

    expect($urls)->toContain('https://www.eksempel.no/')->toContain('https://eksempel.no/om-oss')->toContain('https://www.eksempel.no/produkter');
});

it('skips a subpage that redirects to another site', function () {
    [$reader] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/ut">Ut</a><a href="/inn">Inn</a></nav>')),
        'https://eksempel.no/ut' => FakePageTransport::redirect('https://annen.no/side'),
        'https://annen.no/side' => FakePageTransport::html(page('Annen')),
        'https://eksempel.no/inn' => FakePageTransport::html(page('Inn')),
    ]);

    expect(array_map(fn ($p) => $p->url, $reader->read('eksempel.no')))->toBe(['https://eksempel.no/', 'https://eksempel.no/inn']);
});

it('skips a subpage that redirects to a private address and carries on', function () {
    [$reader, $transport] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/intern">Intern</a><a href="/inn">Inn</a></nav>')),
        'https://eksempel.no/intern' => FakePageTransport::redirect('https://intern.eksempel.no/'),
        'https://eksempel.no/inn' => FakePageTransport::html(page('Inn')),
    ], ['eksempel.no' => ['93.184.216.34'], 'intern.eksempel.no' => ['192.168.1.1']]);

    $urls = array_map(fn ($p) => $p->url, $reader->read('eksempel.no'));

    expect($urls)->toBe(['https://eksempel.no/', 'https://eksempel.no/inn'])
        ->and($transport->urls())->not->toContain('https://intern.eksempel.no/');
});

it('stops when the front page cannot be fetched', function (FetchFailed|TransportResponse $response, string $reason) {
    [$reader] = reader(['https://eksempel.no/' => $response]);

    try {
        $reader->read('eksempel.no');
    } catch (FetchFailed $e) {
        expect($e->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException('Expected a FetchFailed.');
})->with([
    'not found' => [fn () => new TransportResponse(404, 'text/html', null, ''), FetchFailed::HTTP_ERROR],
    'pdf' => [fn () => new TransportResponse(200, 'application/pdf', null, '%PDF'), FetchFailed::UNSUPPORTED_CONTENT],
    'too large' => [fn () => new TransportResponse(200, 'text/html', null, 'x', truncated: true), FetchFailed::TOO_LARGE],
    'timeout' => [fn () => FetchFailed::because(FetchFailed::TIMEOUT), FetchFailed::TIMEOUT],
]);

it('refuses a front page on a host that resolves to a private address', function () {
    [$reader, $transport] = reader(['https://eksempel.no/' => FakePageTransport::html(page('Forside'))], ['*' => ['10.0.0.1']]);

    expect(fn () => $reader->read('eksempel.no'))->toThrow(FetchFailed::class)
        ->and($transport->requests)->toBe([]);
});

it('ignores a sitemap that is empty, has a document type, is an index, or is broken XML', function (string $body) {
    [$reader] = reader([
        'https://eksempel.no/' => FakePageTransport::html(page('Forside', '<nav><a href="/fra-navigasjon">Nav</a></nav>')),
        'https://eksempel.no/sitemap.xml' => FakePageTransport::html($body, 'text/xml'),
        'https://eksempel.no/fra-navigasjon' => FakePageTransport::html(page('Navigasjon')),
        'https://eksempel.no/fra-sitemap' => FakePageTransport::html(page('Sitemap')),
    ]);

    expect(array_map(fn ($p) => $p->url, $reader->read('eksempel.no')))->toBe(['https://eksempel.no/', 'https://eksempel.no/fra-navigasjon']);
})->with([
    'entity expansion' => '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><urlset><url><loc>https://eksempel.no/fra-sitemap</loc></url></urlset>',
    'external entity' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset><url><loc>https://eksempel.no/fra-sitemap</loc></url></urlset>',
    'index' => '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://eksempel.no/fra-sitemap</loc></sitemap></sitemapindex>',
    'broken' => '<urlset><url><loc>https://eksempel.no/fra-sitemap</loc></url>',
    'empty' => '',
    'only whitespace' => "  \n ",
]);

it('cuts the text of a page and never executes or keeps scripts and styles', function () {
    $html = page('Tittel', '<script>alert("skript")</script><style>.x{color:red}</style><noscript>ingen js</noscript><svg><text>tegning</text></svg><h1>Overskrift</h1><p>Avsnitt   med   mellomrom</p><template>mal</template>'.str_repeat('<p>lang tekst</p>', 2000), '<meta name="description" content="  En beskrivelse ">');
    $parsed = HtmlPage::parse($html, 'https://eksempel.no/', 6000);

    expect($parsed->title)->toBe('Tittel')
        ->and($parsed->description)->toBe('En beskrivelse')
        ->and($parsed->text)->toContain('Overskrift')->toContain('Avsnitt med mellomrom')
        ->and($parsed->text)->not->toContain('skript')->not->toContain('color:red')->not->toContain('tegning')->not->toContain('ingen js')->not->toContain('mal')
        ->and(mb_strlen($parsed->text))->toBeLessThanOrEqual(6000);
});

it('survives broken markup and binary junk', function () {
    $parsed = HtmlPage::parse("<p>uten slutt <b>fet <a href='/x'>lenke</p></div><<>>\x00\xff\xfe", 'https://eksempel.no/', 6000);

    expect($parsed->text)->toContain('uten slutt')->and($parsed->links)->toBe(['https://eksempel.no/x']);
});
