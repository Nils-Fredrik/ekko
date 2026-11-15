<?php

namespace App\Onboarding\Fetch;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The parts of a fetched HTML page the wizard needs: readable text, title, description and links. The page is
 * untrusted. It is parsed without network access, scripts and styles are dropped, and the text is cut to a
 * fixed length. Nothing is executed or rendered.
 */
final readonly class HtmlPage
{
    /**
     * @param  list<string>  $navigationLinks  links in <nav> and <header>, in page order
     * @param  list<string>  $links  all other links, in page order
     */
    private function __construct(
        public string $title,
        public string $description,
        public string $text,
        public array $navigationLinks,
        public array $links,
    ) {}

    public static function parse(string $html, string $baseUrl, int $maxTextChars): self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $title = self::clean($xpath->evaluate('string(//title)'));
        $description = '';
        foreach ($xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content') ?: [] as $node) {
            $description = self::clean($node->nodeValue ?? '');
            break;
        }

        $navigation = self::links($xpath, '//nav//a[@href] | //header//a[@href]', $baseUrl);
        $links = array_values(array_diff(self::links($xpath, '//a[@href]', $baseUrl), $navigation));

        foreach ($xpath->query('//script | //style | //noscript | //template | //svg | //iframe | //head') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $text = $body === null ? '' : self::text($body);

        return new self($title, $description, mb_substr($text, 0, $maxTextChars), $navigation, $links);
    }

    /** @return list<string> */
    private static function links(DOMXPath $xpath, string $query, string $baseUrl): array
    {
        $links = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $resolved = UrlResolver::resolve($baseUrl, $node->getAttribute('href'));
                if ($resolved !== null) {
                    $links[$resolved] = true;
                }
            }
        }

        return array_keys($links);
    }

    private static function text(DOMNode $node): string
    {
        $parts = [];
        $walk = function (DOMNode $current) use (&$walk, &$parts): void {
            foreach ($current->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $parts[] = $child->nodeValue ?? '';
                } elseif ($child instanceof DOMElement) {
                    $block = in_array(strtolower($child->tagName), ['p', 'div', 'li', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article', 'tr', 'ul', 'ol', 'header', 'footer', 'nav'], true);
                    $parts[] = $block ? "\n" : '';
                    $walk($child);
                    $parts[] = $block ? "\n" : '';
                }
            }
        };
        $walk($node);

        $lines = array_map(self::clean(...), explode("\n", implode('', $parts)));

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', mb_scrub($value)));
    }
}
