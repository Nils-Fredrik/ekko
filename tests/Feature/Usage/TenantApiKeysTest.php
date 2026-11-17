<?php

use App\Engines\Credentials\ApiKeyProvider;
use App\Engines\Credentials\TenantApiKeys;
use App\Models\Activity;
use App\Models\Engine;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('a tenant admin verifies and stores a hidden encrypted API key with a masked audit entry', function (
    ApiKeyProvider $provider, string $url, string $header, string $value, string $responseField,
) {
    Http::preventStrayRequests()->fake([$url => Http::response([$responseField => []])]);
    $tenant = Team::factory()->tenant()->create(['byok_enabled' => true]);
    $teamContext = app(TenantContext::class);
    $rls = app(TenantRls::class);
    $keys = app(TenantApiKeys::class);
    $other = Team::factory()->tenant()->create(['byok_enabled' => true]);
    $engine = Engine::factory()->create(['provider' => $provider->value, 'method' => 'api']);

    $teamContext->run($tenant->id, fn () => $rls->run([$tenant->id], function () use ($keys, $tenant, $provider, $engine) {
        $key = $keys->store($tenant->owner, $provider, 'test-api-key');
        $audit = Activity::query()->where('subject_type', TenantApiKey::class)->sole();

        expect($keys->forEngine($tenant, $engine)?->key)->toBe('test-api-key')
            ->and($key->last_verified_at)->not->toBeNull()
            ->and(DB::table('tenant_api_keys')->value('key'))->not->toBe('test-api-key')
            ->and($key->toArray())->not->toHaveKey('key')
            ->and($audit->causer_id)->toBe($tenant->owner->id)
            ->and($audit->attribute_changes['attributes']['key'])->toBe('[changed]')
            ->and($audit->toJson())->not->toContain('test-api-key');
    }));

    $teamContext->run($other->id, fn () => $rls->run([$other->id], function () use ($keys, $tenant, $other, $engine) {
        expect($keys->forEngine($other, $engine))->toBeNull()
            ->and(DB::table('tenant_api_keys')->count())->toBe(0)
            ->and(fn () => $keys->forEngine($tenant, $engine))->toThrow(AuthorizationException::class);
    }));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === $url && $request->hasHeader($header, $value));
})->with([
    'OpenAI' => [ApiKeyProvider::OpenAi, 'https://eu.api.openai.com/v1/models', 'Authorization', 'Bearer test-api-key', 'data'],
    'Anthropic' => [ApiKeyProvider::Anthropic, 'https://api.anthropic.com/v1/models', 'x-api-key', 'test-api-key', 'data'],
    'Google' => [ApiKeyProvider::Google, 'https://generativelanguage.googleapis.com/v1beta/models', 'x-goog-api-key', 'test-api-key', 'models'],
    'Perplexity' => [ApiKeyProvider::Perplexity, 'https://api.perplexity.ai/v1/skills?limit=1', 'Authorization', 'Bearer test-api-key', 'skills'],
]);

test('unverified and unauthorized key changes leave the verified credential intact', function (
    ApiKeyProvider $provider, string $url, string $responseField, int $status,
) {
    Http::preventStrayRequests()->fake([
        // Perplexity's public model catalog can return success even for an invalid key.
        'https://api.perplexity.ai/v1/models' => Http::response(['data' => []]),
        $url => Http::sequence()->push([$responseField => []])->push(['error' => 'test-invalid-key'], $status),
    ]);
    $tenant = Team::factory()->tenant()->create(['byok_enabled' => true]);
    $reader = User::factory()->create();
    $tenant->users()->attach($reader, ['role' => 'reader']);

    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($tenant, $reader, $provider) {
        $keys = app(TenantApiKeys::class);
        $verifiedAt = $keys->store($tenant->owner, $provider, 'test-valid-key')->refresh()->last_verified_at;
        $this->travel(1)->minute();

        expect(fn () => $keys->store($tenant->owner, $provider, 'test-invalid-key'))
            ->toThrow(ValidationException::class, __('usage.invalid_key'))
            ->and(fn () => $keys->store($reader, $provider, 'test-new-key'))
            ->toThrow(AuthorizationException::class);

        $stored = TenantApiKey::query()->sole();
        expect($stored->key)->toBe('test-valid-key')
            ->and($stored->last_verified_at)->toEqual($verifiedAt)
            ->and(Activity::query()->where('subject_type', TenantApiKey::class)->count())->toBe(1);
    }));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === $url && $request->hasHeader('Authorization', 'Bearer test-invalid-key'));
})->with([
    'OpenAI' => [ApiKeyProvider::OpenAi, 'https://eu.api.openai.com/v1/models', 'data', 403],
    'Perplexity' => [ApiKeyProvider::Perplexity, 'https://api.perplexity.ai/v1/skills?limit=1', 'skills', 401],
]);

test('a tenant admin can set and clear only the BYOK budget', function () {
    $tenant = Team::factory()->tenant()->create(['byok_enabled' => true]);
    $reader = User::factory()->create();
    $tenant->users()->attach($reader, ['role' => 'reader']);

    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($tenant, $reader) {
        $keys = app(TenantApiKeys::class);

        expect(fn () => $keys->setBudget($reader, '33.00'))->toThrow(AuthorizationException::class)
            ->and(fn () => $keys->setBudget($tenant->owner, '-1'))->toThrow(ValidationException::class);

        $keys->setBudget($tenant->owner, '12.30');
        expect($tenant->refresh()->monthly_byok_budget_usd)->toBe('12.30');

        $keys->setBudget($tenant->owner, null);
        expect($tenant->refresh()->monthly_byok_budget_usd)->toBeNull()
            ->and(Activity::query()->where('event', 'byok.budget_changed')->count())->toBe(2);
    }));
});
