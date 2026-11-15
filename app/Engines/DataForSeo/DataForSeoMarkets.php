<?php

namespace App\Engines\DataForSeo;

use App\Engines\EngineCallFailed;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

final readonly class DataForSeoMarkets
{
    public function __construct(private DataForSeoClient $client) {}

    public function supports(string $country, string $language): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $country) === 1
            && preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]+)*$/D', $language) === 1
            && is_int(config("engines.dataforseo.locations.{$country}"))
            && is_string(config("engines.dataforseo.languages.{$language}"));
    }

    /**
     * @return array{location_code: int, language_code: string}
     */
    public function parameters(
        string $country,
        string $language,
        ?string $city,
        ?string $locationsPath,
        #[SensitiveParameter] ?string $apiKey,
    ): array {
        if (! $this->supports($country, $language)) {
            throw new EngineCallFailed('dataforseo_unsupported_market');
        }

        $countryCode = config()->integer("engines.dataforseo.locations.{$country}");
        $languageCode = config()->string("engines.dataforseo.languages.{$language}");
        $locationCode = $countryCode;

        if ($locationsPath !== null && $city !== null && trim($city) !== '') {
            $locations = Cache::remember("dataforseo:locations:{$locationsPath}:{$country}", now()->addDay(),
                fn (): array => $this->cities($locationsPath, $country, $apiKey));
            $locationCode = $locations[mb_strtolower(trim($city))] ?? $countryCode;
        }

        return ['location_code' => $locationCode, 'language_code' => $languageCode];
    }

    /** @return array<string, int> */
    private function cities(string $path, string $country, #[SensitiveParameter] ?string $apiKey): array
    {
        $raw = $this->client->request("{$path}/{$country}", apiKey: $apiKey);
        $results = data_get($raw, 'tasks.0.result');

        if (($raw['status_code'] ?? null) !== 20000 || data_get($raw, 'tasks.0.status_code') !== 20000
            || ! is_array($results)) {
            throw new EngineCallFailed('dataforseo_location_lookup_failed');
        }

        $cities = [];

        foreach ($results as $location) {
            if (! is_array($location) || ($location['country_iso_code'] ?? null) !== $country
                || ($location['location_type'] ?? null) !== 'City'
                || ! is_string($location['location_name'] ?? null)
                || ! is_int($location['location_code'] ?? null)) {
                continue;
            }

            $fullName = mb_strtolower($location['location_name']);
            $city = explode(',', $fullName, 2)[0];

            if ($fullName !== $city) {
                $cities[$fullName] = $location['location_code'];
            }

            // An unqualified label can match more than one city in the country.
            $cities[$city] = array_key_exists($city, $cities) ? null : $location['location_code'];
        }

        return array_filter($cities, is_int(...));
    }
}
