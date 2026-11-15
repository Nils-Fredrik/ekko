<?php

namespace App\Onboarding\Model;

use App\Onboarding\Fetch\SiteUrl;

/**
 * Turns the model's JSON into a proposal the wizard can show (docs/spec.md 13). The schema fixes the shape
 * but not counts or lengths, so they are enforced here, and everything the model returns is treated as
 * untrusted: text is trimmed and length limited, domains are checked, duplicates are removed and
 * prompts with an email address or a phone number are dropped (docs/spec.md 16).
 *
 * parse*() returns null when the reply is not usable at all (not JSON, or the wrong shape). Otherwise it
 * returns the proposal and the codes of what was dropped, as warnings.
 */
final class ProposalParser
{
    /**
     * @return array{proposal: array<string, mixed>, warnings: list<string>}|null
     */
    public function parseBrand(string $json, string $brandName, string $ownDomain): ?array
    {
        $data = $this->decode($json, ['industry', 'category', 'name_variants', 'competitors', 'topics', 'prompts']);
        if ($data === null || ! is_string($data['industry']) || ! is_string($data['category'])
            || ! is_array($data['name_variants']) || ! is_array($data['competitors']) || ! is_array($data['topics']) || ! is_array($data['prompts'])) {
            return null;
        }

        /** @var array{max_competitors: int, max_topics: int, max_prompts: int, max_core_prompts: int, max_name_variants: int} $max */
        $max = config('ekko.wizard.suggestions');
        $warnings = [];

        $variants = [];
        $seen = [mb_strtolower($brandName) => true];
        foreach ($data['name_variants'] as $variant) {
            $variant = $this->text($variant, 100);
            if ($variant !== null && ! isset($seen[mb_strtolower($variant)])) {
                $seen[mb_strtolower($variant)] = true;
                $variants[] = $variant;
            }
        }

        $competitors = [];
        $domains = [$this->bareHost($ownDomain) => true];
        foreach ($data['competitors'] as $competitor) {
            if (! is_array($competitor)) {
                continue;
            }
            $name = $this->text($competitor['name'] ?? null, 100);
            $domain = is_string($competitor['domain'] ?? null) ? SiteUrl::hostFromUserInput($competitor['domain']) : null;
            if ($name === null || $domain === null) {
                $warnings[] = 'competitor_dropped';

                continue;
            }
            $domain = $this->bareHost($domain);
            if (isset($domains[$domain])) {
                continue;
            }
            $domains[$domain] = true;
            $competitors[] = ['name' => $name, 'domain' => $domain];
        }

        $topics = [];
        foreach ($data['topics'] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $name = $this->text($topic['name'] ?? null, 100);
            $key = $this->key($topic['cross_market_key'] ?? null);
            if ($name === null || $key === null || isset($topics[$key])) {
                $warnings[] = 'topic_dropped';

                continue;
            }
            $topics[$key] = ['name' => $name, 'cross_market_key' => $key];
        }
        $topics = array_slice($topics, 0, $max['max_topics'], true);

        [$prompts, $promptWarnings] = $this->prompts($data['prompts'], array_keys($topics), $max['max_prompts'], $max['max_core_prompts']);

        return [
            'proposal' => [
                'industry' => $this->text($data['industry'], 120) ?? '',
                'category' => $this->text($data['category'], 120) ?? '',
                'name_variants' => array_slice($variants, 0, $max['max_name_variants']),
                'competitors' => array_slice($competitors, 0, $max['max_competitors']),
                'topics' => array_values($topics),
                'prompts' => $prompts,
            ],
            'warnings' => array_values(array_unique([...$warnings, ...$promptWarnings])),
        ];
    }

    /**
     * @param  list<string>  $topicKeys  the keys the brand has; a prompt for another key is dropped
     * @return array{proposal: array<string, mixed>, warnings: list<string>}|null
     */
    public function parseMarket(string $json, array $topicKeys, int $maxPrompts): ?array
    {
        $data = $this->decode($json, ['prompts']);
        if ($data === null || ! is_array($data['prompts'])) {
            return null;
        }

        [$prompts, $warnings] = $this->prompts($data['prompts'], $topicKeys, $maxPrompts, (int) config('ekko.wizard.suggestions.max_core_prompts'));

        return ['proposal' => ['prompts' => $prompts], 'warnings' => $warnings];
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  list<string>  $topicKeys
     * @return array{0: list<array{text: string, cross_market_key: string, intent: string, funnel_stage: string, priority: string}>, 1: list<string>}
     */
    private function prompts(array $raw, array $topicKeys, int $max, int $maxCore): array
    {
        $prompts = [];
        $warnings = [];
        $seen = [];
        $core = 0;

        foreach ($raw as $prompt) {
            if (count($prompts) >= $max) {
                break;
            }
            if (! is_array($prompt)) {
                continue;
            }
            $text = $this->text($prompt['text'] ?? null, 300);
            // An existing key is kept exactly as stored (it may not be in the normal form); a new one is normalised.
            $rawKey = $prompt['cross_market_key'] ?? null;
            $key = is_string($rawKey) && in_array($rawKey, $topicKeys, true) ? $rawKey : $this->key($rawKey);
            $intent = $prompt['intent'] ?? null;
            $stage = $prompt['funnel_stage'] ?? null;

            if ($text === null || mb_strlen($text) < 5 || $key === null || ! in_array($key, $topicKeys, true)
                || ! in_array($intent, OnboardingSchema::INTENTS, true) || ! in_array($stage, OnboardingSchema::FUNNEL_STAGES, true)) {
                $warnings[] = 'prompt_dropped';

                continue;
            }
            if ($this->hasPersonalData($text)) {
                $warnings[] = 'prompt_personal_data';

                continue;
            }
            $fingerprint = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $isCore = ($prompt['is_core'] ?? false) === true && $core < $maxCore;
            $core += $isCore ? 1 : 0;

            $prompts[] = [
                'text' => $text,
                'cross_market_key' => $key,
                'intent' => $intent,
                'funnel_stage' => $stage,
                'priority' => $isCore ? 'core' : 'standard',
            ];
        }

        return [$prompts, array_values(array_unique($warnings))];
    }

    /**
     * @param  list<string>  $required
     * @return array<string, mixed>|null
     */
    private function decode(string $json, array $required): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                return null;
            }
        }

        return $data;
    }

    private function text(mixed $value, int $maxChars): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', mb_scrub($value)));

        return $value === '' ? null : mb_substr($value, 0, $maxChars);
    }

    private function key(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');

        return $key === '' ? null : substr($key, 0, 64);
    }

    private function bareHost(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function hasPersonalData(string $text): bool
    {
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $text) === 1) {
            return true;
        }
        if (preg_match_all('/\+?[\d][\d\s().\-]{6,}[\d]/u', $text, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                if (strlen((string) preg_replace('/\D/', '', $candidate)) >= 8) {
                    return true;
                }
            }
        }

        return false;
    }
}
