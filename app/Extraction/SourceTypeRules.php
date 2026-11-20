<?php

namespace App\Extraction;

use App\Detection\UrlNormalizer;

/**
 * Deterministic rules for a citation's source_type (docs/spec.md 8.2, ADR 0011 point 10), applied on top of the
 * model's classification before a citation is stored:
 *
 * 1. A domain that belongs to a brand of the tenant or to a competitor (ownership own or competitor) is always
 *    `other`: it is the company's own website, also its webshop, offer pages and blog. The model tended to call
 *    those `marketplace`.
 * 2. A small table of well-known hosts has a fixed type, so the same site is never classified differently from
 *    one answer to the next (Reddit is a forum, not social media; Wikipedia is a wiki).
 * 3. Anything else keeps the model's type.
 *
 * Hosts are compared in their normalized form (lowercase, punycode, no www.), and an entry covers its subdomains.
 */
final class SourceTypeRules
{
    /** @var array<string, list<string>> source type => hosts (an entry also covers its subdomains) */
    private const HOSTS = [
        'wiki' => ['wikipedia.org', 'wiktionary.org', 'snl.no', 'lokalhistoriewiki.no', 'ne.se', 'fandom.com'],
        'forum' => [
            'reddit.com', 'quora.com', 'stackoverflow.com', 'stackexchange.com', 'flashback.org', 'familjeliv.se',
            'kvinneguiden.no', 'diskusjon.no',
        ],
        'review' => ['trustpilot.com', 'tripadvisor.com', 'tripadvisor.no', 'tripadvisor.se', 'yelp.com', 'g2.com', 'capterra.com'],
        'social' => [
            'facebook.com', 'instagram.com', 'linkedin.com', 'x.com', 'twitter.com', 'tiktok.com', 'youtube.com',
            'youtu.be', 'pinterest.com', 'threads.net', 'snapchat.com',
        ],
        'marketplace' => [
            'finn.no', 'blocket.se', 'tradera.com', 'ebay.com', 'etsy.com', 'amazon.com', 'amazon.se', 'amazon.de',
            'amazon.co.uk', 'prisjakt.no', 'prisjakt.nu', 'pricerunner.se', 'pricerunner.dk', 'pricerunner.com',
        ],
        'gov' => [
            'gov', 'gov.uk', 'europa.eu', 'regjeringen.no', 'stortinget.no', 'kommune.no', 'statsforvalteren.no',
            'nav.no', 'skatteetaten.no', 'mattilsynet.no', 'helsenorge.no', 'helsedirektoratet.no', 'fhi.no', 'ssb.no',
            'brreg.no', 'altinn.no', 'forbrukertilsynet.no', 'forbrukerradet.no', 'government.se', 'riksdagen.se',
            'skatteverket.se', 'livsmedelsverket.se', 'konsumentverket.se', 'folkhalsomyndigheten.se', 'scb.se',
            'bolagsverket.se', 'verksamt.se', '1177.se',
        ],
        'news' => [
            'nrk.no', 'vg.no', 'dagbladet.no', 'aftenposten.no', 'dn.no', 'e24.no', 'tv2.no', 'nettavisen.no', 'bt.no',
            'svt.se', 'sr.se', 'aftonbladet.se', 'expressen.se', 'dn.se', 'svd.se', 'gp.se', 'di.se',
        ],
    ];

    /**
     * The source type to store for a citation.
     *
     * @param  string  $domain  the normalized domain of the citation
     * @param  string|null  $ownership  own, competitor or third_party (DomainOwnership)
     * @param  string|null  $modelType  the model's source_type, null when the model gave none
     */
    public function resolve(string $domain, ?string $ownership, ?string $modelType): ?string
    {
        if ($ownership === 'own' || $ownership === 'competitor') {
            return 'other';
        }

        return $this->knownHost($domain) ?? $modelType;
    }

    /** The fixed type of a well-known host, or null. */
    public function knownHost(string $domain): ?string
    {
        $host = UrlNormalizer::hostKey($domain);
        foreach (self::HOSTS as $type => $hosts) {
            foreach ($hosts as $known) {
                if ($host === $known || str_ends_with($host, '.'.$known)) {
                    return $type;
                }
            }
        }

        return null;
    }
}
