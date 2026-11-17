<?php

use App\Scheduling\CloudRunAccessToken;
use App\Scheduling\DispatcherLauncher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $token = Mockery::mock(CloudRunAccessToken::class);
    $token->shouldReceive('get')->andReturn('fixture-token');
    app()->instance(CloudRunAccessToken::class, $token);
});

it('starts only the fixed poll dispatcher without overrides even when the queue is empty', function () {
    Http::preventStrayRequests()->fake([
        '*/executions*' => Http::sequence()->push(['executions' => [['completionTime' => '2026-11-17T00:00:00Z']], 'nextPageToken' => 'next'])->push(['executions' => []]),
        '*:run' => Http::response(['name' => 'operation']),
    ]);
    expect(app(DispatcherLauncher::class)->start())->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://run.googleapis.com/v2/projects/ekko-fixture/locations/europe-west1/jobs/ekko-wake:run'
        && $request->body() === '{}');
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ekko-worker'));
});

it('does not overlap an active poll dispatcher', function () {
    Http::preventStrayRequests()->fake(['*/executions*' => Http::response(['executions' => [['name' => 'active']]])]);
    expect(app(DispatcherLauncher::class)->start())->toBeFalse();
    Http::assertSentCount(1);
});

it('fails closed when the execution list is incomplete or unavailable', function (array $body, int $status) {
    Http::preventStrayRequests()->fake(['*/executions*' => Http::response($body, $status)]);
    expect(fn () => app(DispatcherLauncher::class)->start())->toThrow(RuntimeException::class);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([
    'failed list' => [[], 403],
    'repeated page' => [['executions' => [], 'nextPageToken' => 'same'], 200],
]);

it('does not contact Cloud Run when disabled or configured with an invalid project', function () {
    Http::preventStrayRequests()->fake();
    config(['collection.cloud_run.enabled' => false]);
    expect(app(DispatcherLauncher::class)->start())->toBeFalse();
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'invalid/path']);
    expect(fn () => app(DispatcherLauncher::class)->start())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
