<?php

namespace App\Detection;

/**
 * Decides whether a cited domain belongs to a brand of the tenant ("own"), to a competitor, or to a third
 * party (docs/spec.md 8.1). Brand domains match subdomains only when include_subdomains is set; competitor
 * domains always match their subdomains, since competitor_domains has no such flag (ADR 0010). A brand
 * domain wins over a competitor domain, and the most specific (longest) competitor domain wins.
 */
final class DomainOwnership
{
    /** @var list<string> */
    private array $brandExact = [];

    /** @var list<string> */
    private array $brandWithSubdomains = [];

    /** @var list<array{domain: string, competitorId: int}> */
    private array $competitors = [];

    /**
     * @param  iterable<array{domain: string, include_subdomains: bool}>  $brandDomains
     * @param  iterable<array{domain: string, competitor_id: int}>  $competitorDomains
     */
    public function __construct(iterable $brandDomains, iterable $competitorDomains)
    {
        foreach ($brandDomains as $row) {
            $domain = self::clean($row['domain']);
            if ($domain === '') {
                continue;
            }
            if ($row['include_subdomains']) {
                $this->brandWithSubdomains[] = $domain;
            } else {
                $this->brandExact[] = $domain;
            }
        }

        foreach ($competitorDomains as $row) {
            $domain = self::clean($row['domain']);
            if ($domain !== '') {
                $this->competitors[] = ['domain' => $domain, 'competitorId' => $row['competitor_id']];
            }
        }

        usort($this->competitors, fn (array $a, array $b): int => strlen($b['domain']) <=> strlen($a['domain']));
    }

    /**
     * @return array{ownership: 'own'|'competitor'|'third_party', competitor_id: int|null}
     */
    public function classify(string $domain): array
    {
        $domain = self::clean($domain);

        if (in_array($domain, $this->brandExact, true)) {
            return ['ownership' => 'own', 'competitor_id' => null];
        }
        foreach ($this->brandWithSubdomains as $own) {
            if (self::covers($own, $domain)) {
                return ['ownership' => 'own', 'competitor_id' => null];
            }
        }

        foreach ($this->competitors as $competitor) {
            if (self::covers($competitor['domain'], $domain)) {
                return ['ownership' => 'competitor', 'competitor_id' => $competitor['competitorId']];
            }
        }

        return ['ownership' => 'third_party', 'competitor_id' => null];
    }

    private static function covers(string $registered, string $domain): bool
    {
        return $domain === $registered || str_ends_with($domain, '.'.$registered);
    }

    /** Comparable host without scheme, path, port and www. (punycode for æ/ø/å), so a stored value may be a bare domain or a URL. */
    private static function clean(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $value);
        $value = (string) preg_replace('#[/:?\#].*$#', '', $value);

        return UrlNormalizer::hostKey($value);
    }
}
