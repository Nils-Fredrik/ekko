<?php

namespace App\Onboarding\Fetch;

use DOMDocument;

/**
 * Reads a brand's site for the wizard (docs/spec.md 13 and 16): the front page and up to five central
 * subpages, from the sitemap or else from the navigation. At most ekko.wizard.fetch.max_pages pages are
 * fetched, all through {@see SafeHttpFetcher}, within one overall time limit. The sitemap is one extra
 * request and is not counted as a page. A subpage that fails is skipped; a front page that fails stops
 * the reading, since there is nothing to analyse.
 */
final class SiteReader
{
    private const HTML = ['text/html', 'application/xhtml+xml'];

    private const XML = ['application/xml', 'text/xml'];

    /** Words in a path that mark a page describing what the business does, best first. */
    private const HINTS = [
        'om-oss', 'om', 'about', 'about-us', 'produkter', 'products', 'produkt', 'tjenester', 'tjanster', 'services', 'service',
        'losninger', 'lösningar', 'solutions', 'priser', 'pris', 'pricing', 'kontakt', 'contact',
    ];

    private const SKIPPED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'pdf', 'zip', 'gz', 'mp3', 'mp4', 'mov', 'avi', 'css', 'js', 'json', 'xml', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * @return list<SitePage> the front page first
     *
     * @throws FetchFailed when the front page cannot be fetched
     */
    public function read(string $host): array
    {
        /** @var array{max_pages: int, total_timeout_seconds: int, max_text_chars_per_page: int} $limits */
        $limits = config('ekko.wizard.fetch');
        $deadline = microtime(true) + $limits['total_timeout_seconds'];

        $front = $this->fetcher->get("https://{$host}/", self::HTML, $deadline);
        $frontHtml = HtmlPage::parse($front->body, $front->url, $limits['max_text_chars_per_page']);
        $pages = [new SitePage($front->url, $frontHtml->title, $frontHtml->description, $frontHtml->text)];

        $site = $this->siteKey((string) parse_url($front->url, PHP_URL_HOST));
        $wanted = $limits['max_pages'] - 1;

        $candidates = $this->sitemapCandidates($front->url, $site, $deadline);
        $navigation = $this->sameSite([...$frontHtml->navigationLinks, ...$frontHtml->links], $site);
        $candidates = array_values(array_unique([...$candidates, ...$navigation]));
        $candidates = array_values(array_filter($candidates, fn (string $url): bool => $this->path($url) !== '/'));

        // Attempts are counted, not only pages kept, so a site full of broken links never costs more than the limit.
        foreach (array_slice($this->rank($candidates), 0, $wanted) as $url) {
            try {
                $page = $this->fetcher->get($url, self::HTML, $deadline);
            } catch (FetchFailed) {
                continue;
            }
            // A redirect may have left the site, or ended on a page already read.
            if ($this->siteKey((string) parse_url($page->url, PHP_URL_HOST)) !== $site || in_array($page->url, array_map(fn (SitePage $p): string => $p->url, $pages), true)) {
                continue;
            }
            $html = HtmlPage::parse($page->body, $page->url, $limits['max_text_chars_per_page']);
            $pages[] = new SitePage($page->url, $html->title, $html->description, $html->text);
        }

        return $pages;
    }

    /** @return list<string> */
    private function sitemapCandidates(string $frontUrl, string $site, float $deadline): array
    {
        $root = 'https://'.parse_url($frontUrl, PHP_URL_HOST).'/sitemap.xml';
        try {
            $sitemap = $this->fetcher->get($root, self::XML, $deadline);
        } catch (FetchFailed) {
            return [];
        }

        // A sitemap is plain data. A document type declaration is how entity expansion attacks start, so it is refused.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $sitemap->body) === 1) {
            return [];
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($sitemap->body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        // A sitemap index lists other sitemaps, not pages. Navigation is used instead.
        if (! $loaded || $document->getElementsByTagName('sitemapindex')->length > 0) {
            return [];
        }

        $urls = [];
        foreach ($document->getElementsByTagName('loc') as $loc) {
            $urls[] = trim($loc->textContent);
            if (count($urls) >= 500) {
                break;
            }
        }

        return $this->sameSite($urls, $site);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string> https addresses on the same site, without query and fragment, and not a file
     */
    private function sameSite(array $urls, string $site): array
    {
        $kept = [];
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
                continue;
            }
            $host = SiteUrl::normaliseHost($parts['host'] ?? '');
            if ($host === null || $this->siteKey($host) !== $site) {
                continue;
            }
            $path = $parts['path'] ?? '/';
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, self::SKIPPED_EXTENSIONS, true)) {
                continue;
            }
            $kept["https://{$host}{$path}"] = true;
        }

        return array_keys($kept);
    }

    /**
     * Pages that look like "about", "products" and "services" first, then the shallowest paths. The sort is
     * stable, so pages keep the order they had (sitemap first, then navigation) within a tie.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function rank(array $urls): array
    {
        $scored = [];
        foreach ($urls as $index => $url) {
            $segments = array_values(array_filter(explode('/', $this->path($url))));
            $hint = count(array_intersect(array_map('strtolower', $segments), self::HINTS)) > 0 ? 0 : 1;
            $scored[] = [$hint, count($segments), $index, $url];
        }
        usort($scored, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_column($scored, 3);
    }

    private function path(string $url): string
    {
        return rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/';
    }

    /** example.no and www.example.no are the same site. */
    private function siteKey(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
