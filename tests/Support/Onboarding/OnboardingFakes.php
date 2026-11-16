<?php

namespace Tests\Support\Onboarding;

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\HostResolver;
use App\Onboarding\Fetch\PageTransport;
use App\Onboarding\Fetch\TransportResponse;
use App\Onboarding\Model\OnboardingModelClient;

/** Binds fakes for the wizard's three outside dependencies (DNS, HTTP, the model) and gives sample data. */
final class OnboardingFakes
{
    public function __construct(
        public readonly FakeHostResolver $resolver,
        public readonly FakePageTransport $transport,
        public readonly FakeOnboardingModelClient $model,
    ) {}

    /** @param  array<string, TransportResponse|FetchFailed>|null  $routes */
    public static function bind(?string $modelText = null, ?array $routes = null): self
    {
        $fakes = new self(
            new FakeHostResolver,
            new FakePageTransport($routes ?? [
                'https://eksempel.no/' => FakePageTransport::html(self::frontPage()),
                'https://eksempel.no/om-oss' => FakePageTransport::html('<html><head><title>Om oss</title></head><body><p>Familiebedrift siden 1950</p></body></html>'),
                'https://eksempel.no/produkter' => FakePageTransport::html('<html><head><title>Produkter</title></head><body><p>Løpesko og fritidssko</p></body></html>'),
            ]),
            new FakeOnboardingModelClient($modelText ?? self::brandJson()),
        );
        app()->instance(HostResolver::class, $fakes->resolver);
        app()->instance(PageTransport::class, $fakes->transport);
        app()->instance(OnboardingModelClient::class, $fakes->model);

        return $fakes;
    }

    public static function frontPage(): string
    {
        return '<html><head><title>Eksempel AS</title><meta name="description" content="Vi selger sko"></head>'
            .'<body><nav><a href="/om-oss">Om oss</a><a href="/produkter">Produkter</a></nav><h1>Sko til alle</h1><p>Velkommen.</p></body></html>';
    }

    /** @param  array<string, mixed>  $overrides */
    public static function brandJson(array $overrides = []): string
    {
        $prompts = [];
        foreach (range(1, 12) as $i) {
            $prompts[] = [
                'text' => "Hvilken skobutikk er best for løping nummer {$i}?",
                'cross_market_key' => $i % 2 === 0 ? 'running_shoes' : 'shoe_stores',
                'intent' => 'category',
                'funnel_stage' => 'consideration',
                'is_core' => $i <= 3,
            ];
        }

        return json_encode($overrides + [
            'industry' => 'Retail',
            'category' => 'Footwear',
            'name_variants' => ['Eksempel', 'Eksempel Sko'],
            'competitors' => [
                ['name' => 'Skoringen', 'domain' => 'skoringen.example.no'],
                ['name' => 'Skohuset', 'domain' => 'https://www.skohuset.example.no/'],
            ],
            'topics' => [
                ['name' => 'Løpesko', 'cross_market_key' => 'running_shoes'],
                ['name' => 'Skobutikker', 'cross_market_key' => 'shoe_stores'],
            ],
            'prompts' => $prompts,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param  list<string>  $keys */
    public static function marketJson(array $keys = ['running_shoes']): string
    {
        $prompts = [];
        foreach ($keys as $i => $key) {
            $prompts[] = ['text' => "Vilken löparsko är bäst för nybörjare {$i}?", 'cross_market_key' => $key, 'intent' => 'problem', 'funnel_stage' => 'awareness', 'is_core' => $i === 0];
        }

        return json_encode(['prompts' => $prompts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
