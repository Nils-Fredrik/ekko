<?php

namespace Tests\Support\Onboarding;

use App\Onboarding\Fetch\HostResolver;

/** A resolver with a fixed table. */
final class FakeHostResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param  array<string, list<string>>  $hosts  host => addresses; '*' answers every host not listed */
    public function __construct(public array $hosts = ['*' => ['93.184.216.34']]) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->hosts[$host] ?? $this->hosts['*'] ?? [];
    }
}
