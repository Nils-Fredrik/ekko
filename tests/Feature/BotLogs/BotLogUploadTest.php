<?php

use App\Jobs\ImportBotLog;
use App\Models\BotHitDaily;
use App\Models\BotLogUpload;
use App\Models\Brand;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function testGzipLog(): string
{
    $log = file_get_contents(base_path('tests/Fixtures/bot-logs/combined.txt'));
    $half = intdiv(strlen($log), 2);

    return gzencode(substr($log, 0, $half)).gzencode(substr($log, $half));
}

it('uploads encrypted gzip and processes each UTC day idempotently through the tenant job', function () {
    config(['ekko.bot_logs.bots' => [...config('ekko.bot_logs.bots'), 'CustomIndexer' => 'custom']]);
    Queue::fake();
    $context = app(TenantContext::class);
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->tenant()->for($owner, 'owner')->create();
    $owner->switchTeam($team);
    $brand = $context->run($team->id, function () {
        $brand = Brand::factory()->create(['primary_domain' => 'site.example.test']);
        $brand->domains()->create(['domain' => 'second.example.test']);

        return $brand;
    });

    $this->actingAs($owner)
        ->from(route('bot-log-uploads.index', $team))
        ->post(route('bot-log-uploads.store', $brand), [
            'domain' => 'site.example.test',
            'log' => UploadedFile::fake()->createWithContent('access.log.gz', testGzipLog()),
        ])
        ->assertRedirect(route('bot-log-uploads.index', $team));

    $upload = $context->run($team->id, fn () => BotLogUpload::query()->sole());
    Queue::assertPushed(ImportBotLog::class, fn (ImportBotLog $job) => $job->tenantId === $team->id && $job->uploadId === $upload->id);
    expect($upload->payload)->toBe(base64_encode(testGzipLog()))
        ->and($upload->toArray())->not->toHaveKey('payload')
        ->and(DB::table('bot_log_uploads')->where('id', $upload->id)->value('payload'))->not->toBe($upload->payload);

    Queue::fake()->except([ImportBotLog::class]);
    ImportBotLog::dispatchSync($team->id, $upload->id);
    ImportBotLog::dispatchSync($team->id, $upload->id);

    $context->run($team->id, function () use ($brand, $upload) {
        expect($upload->refresh()->status)->toBe('processed')->and($upload->payload)->toBeNull();
        $rows = BotHitDaily::query()->where('brand_id', $brand->id)->orderBy('date')->orderBy('bot')->get();
        expect($rows->map(fn (BotHitDaily $row) => [$row->date->toDateString(), $row->domain, $row->bot, $row->hits, $row->unique_paths])->all())
            ->toBe([
                ['2026-11-13', 'site.example.test', 'gptbot', 1, 1],
                ['2026-11-14', 'site.example.test', 'claude_searchbot', 1, 1],
                ['2026-11-14', 'site.example.test', 'custom', 1, 1],
                ['2026-11-14', 'site.example.test', 'gptbot', 3, 2],
            ]);
        expect($rows->last()->top_paths)->toBe([['hits' => 2, 'path' => '/shop'], ['hits' => 1, 'path' => '/help']]);
    });
});

it('keeps other domains and rejects a corrupt archive without changing saved counts', function () {
    $context = app(TenantContext::class);
    $team = Team::factory()->tenant()->create();
    [$upload, $validUpload] = $context->run($team->id, function () {
        $brand = Brand::factory()->create(['primary_domain' => 'site.example.test']);
        $brand->domains()->create(['domain' => 'second.example.test']);
        foreach (['site.example.test', 'second.example.test'] as $domain) {
            BotHitDaily::factory()->create(['brand_id' => $brand->id, 'domain' => $domain, 'date' => '2026-11-14', 'hits' => 8]);
        }

        return [
            BotLogUpload::query()->create([
                'brand_id' => $brand->id, 'domain' => 'site.example.test',
                'payload' => base64_encode(substr(testGzipLog(), 0, -3)),
            ]),
            BotLogUpload::query()->create([
                'brand_id' => $brand->id, 'domain' => 'second.example.test',
                'payload' => base64_encode(testGzipLog()),
            ]),
        ];
    });

    ImportBotLog::dispatchSync($team->id, $upload->id);
    ImportBotLog::dispatchSync($team->id, $validUpload->id);

    $context->run($team->id, function () use ($upload, $validUpload) {
        expect($upload->refresh()->status)->toBe('rejected')->and($upload->payload)->toBeNull()
            ->and($validUpload->refresh()->status)->toBe('processed')
            ->and(BotHitDaily::query()->where('domain', 'site.example.test')->sole()->hits)->toBe(8)
            ->and(BotHitDaily::query()->where('domain', 'second.example.test')->where('bot', 'gptbot')->whereDate('date', '2026-11-14')->sole()->hits)
            ->toBe(3);
    });
});

it('does not process another tenant’s upload ID', function () {
    $context = app(TenantContext::class);
    $teamA = Team::factory()->tenant()->create();
    $teamB = Team::factory()->tenant()->create();
    $upload = $context->run($teamB->id, fn () => BotLogUpload::query()->create([
        'brand_id' => Brand::factory()->create(['primary_domain' => 'site.example.test'])->id,
        'domain' => 'site.example.test',
        'payload' => base64_encode(testGzipLog()),
    ]));

    ImportBotLog::dispatchSync($teamA->id, $upload->id);

    $context->run($teamA->id, fn () => expect(BotHitDaily::query()->exists())->toBeFalse());
    $context->run($teamB->id, fn () => expect(BotHitDaily::query()->exists())->toBeFalse()
        ->and($upload->refresh()->status)->toBe('pending'));
});
