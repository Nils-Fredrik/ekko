<?php

use App\Scheduling\CloudRunAccessToken;
use App\Scheduling\WorkerLauncher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function workerQueue(int $size): void
{
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $token = Mockery::mock(CloudRunAccessToken::class);
    $token->shouldReceive('get')->andReturn('fixture-token');
    app()->instance(CloudRunAccessToken::class, $token);
    DB::table('jobs')->insert(array_fill(0, $size, ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]));
}

test('worker launcher paginates before starting the capped task count', function () {
    workerQueue(2001);
    Http::preventStrayRequests()->fake([
        '*/executions*' => Http::sequence()->push(['executions' => [['completionTime' => '2026-11-14T00:00:00Z']], 'nextPageToken' => 'next'])->push(['executions' => []]),
        '*:run' => Http::response(['name' => 'operation']),
    ]);
    expect(app(WorkerLauncher::class)->startIfPending())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), ':run') && $request['overrides']['taskCount'] === 2);
    Http::assertSentCount(3);
});

test('worker launcher does not overlap an active execution or start an empty queue', function () {
    workerQueue(1);
    Http::preventStrayRequests()->fake(['*/executions*' => Http::response(['executions' => [['name' => 'active']]])]);
    expect(app(WorkerLauncher::class)->startIfPending())->toBeFalse();
    DB::table('jobs')->delete();
    expect(app(WorkerLauncher::class)->startIfPending())->toBeFalse();
    Http::assertSentCount(1);
});
