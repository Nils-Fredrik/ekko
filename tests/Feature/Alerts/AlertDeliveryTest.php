<?php

// T24, docs/spec.md 11.1: the daily digest (one message per tenant and day), Slack and e-mail delivery, receipts
// and acknowledgement. Alerts are created directly here; AlertDetectionTest covers when each type fires.

use App\Jobs\EvaluateTenantAlerts;
use App\Mail\AlertDigestMail;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\AlertEvaluation;
use App\Models\AlertRule;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Metrics\MetricsWorld;

const SLACK_WEBHOOK = 'https://hooks.slack.com/services/T000/B000/XXXXXXXX';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->tenant->forceFill(['slack_webhook_url' => SLACK_WEBHOOK])->save();

    $this->admin = User::factory()->create(['locale' => 'sv']);
    $this->w->tenant->users()->attach($this->admin, ['role' => 'admin']);
    $this->editor = User::factory()->create();
    $this->w->tenant->users()->attach($this->editor, ['role' => 'editor']);
    $this->platformAdmin = User::factory()->platformAdmin()->create();
    $this->owner = $this->w->tenant->owner;

    Mail::fake();
    $this->slack = Http::sequence()->whenEmpty(Http::response('ok', 200));
    Http::fake([SLACK_WEBHOOK => $this->slack]);
});

/**
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $channels
 */
function makeAlert(MetricsWorld $w, string $type, array $payload, array $channels = ['in_app', 'email', 'slack'], bool $brandRule = true): Alert
{
    $rule = AlertRule::query()->firstOrCreate(
        ['type' => $type, 'brand_id' => $brandRule ? $w->brand->id : null],
        ['channels' => $channels, 'threshold' => null, 'is_active' => true],
    );

    return Alert::factory()->create(['alert_rule_id' => $rule->id, 'payload' => $payload, 'subject' => uniqid('s', true)]);
}

function visibilityPayload(MetricsWorld $w): array
{
    return [
        'market_id' => $w->market->id, 'engine_key' => $w->engine->key,
        'previous' => ['value' => 0.6, 'n' => 40], 'current' => ['value' => 0.4, 'n' => 38], 'drop_points' => 20.0,
    ];
}

function modelPayload(MetricsWorld $w): array
{
    return ['engine_key' => $w->engine->key, 'date' => '2026-11-15', 'from_model' => 'gpt-a', 'to_model' => 'gpt-b'];
}

function quotaPayload(): array
{
    return ['month' => '2026-11-01', 'threshold_percent' => 80, 'recipient_roles' => ['tenant_admin', 'platform_admin']];
}

function runAlertJob(MetricsWorld $w): void
{
    dispatch_sync(new EvaluateTenantAlerts($w->tenant->id));
}

test('the alerts of a day go out as one Slack message and one e-mail per recipient', function () {
    makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));
    makeAlert($this->w, 'model_change', modelPayload($this->w), brandRule: false);
    makeAlert($this->w, 'quota', quotaPayload(), brandRule: false);

    runAlertJob($this->w);

    // Slack: one message, with every alert, in the tenant's language (nb).
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === SLACK_WEBHOOK
        && str_contains($request['text'], '3 varsler')
        && str_contains($request['text'], 'synlighet i')
        && str_contains($request['text'], 'har byttet hovedmodell')
        && str_contains($request['text'], 'Forbruket har nådd 80 %'));

    // E-mail: tenant admins get all three, the platform admin only the quota alert, the editor nothing.
    Mail::assertSent(AlertDigestMail::class, 3);
    Mail::assertSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->hasTo($this->owner->email) && $mail->count === 3);
    Mail::assertSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->hasTo($this->admin->email) && $mail->count === 3 && $mail->locale === 'sv');
    Mail::assertSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->hasTo($this->platformAdmin->email) && $mail->count === 1);
    Mail::assertNotSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->hasTo($this->editor->email));

    // Receipts: every alert is delivered on both channels and belongs to the day's digest.
    foreach (Alert::query()->get() as $alert) {
        expect($alert->delivered_at)->not->toBeNull()
            ->and($alert->digest_date->toDateString())->toBe('2026-11-16')
            ->and($alert->deliveries['slack']['status'])->toBe('sent')
            ->and($alert->deliveries['email']['status'])->toBe('sent');
    }
    expect(Alert::query()->where('alert_rule_id', AlertRule::query()->where('type', 'quota')->value('id'))->sole()->deliveries['email']['sent_to'])
        ->toEqualCanonicalizing([$this->owner->id, $this->admin->id, $this->platformAdmin->id]);
});

test('integration_error alerts, created by the syncs, are delivered with the rest and show no error details', function () {
    makeAlert($this->w, 'integration_error', ['integration_id' => 1, 'integration_type' => 'ga4', 'error_code' => 'permission_denied']);

    runAlertJob($this->w);

    Http::assertSent(fn ($request) => str_contains($request['text'], 'synkroniseringen mot GA4 har feilet to dager på rad')
        && ! str_contains($request['text'], 'permission_denied'));
    Mail::assertSent(AlertDigestMail::class, 2);
});

test('the digest is sent once a day: later runs send nothing, and later alerts wait for the next day', function () {
    makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));
    runAlertJob($this->w);
    Http::assertSentCount(1);
    Mail::assertSent(AlertDigestMail::class, 2);

    // The next hourly run, and an alert that comes in during the day.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00:00', 'Europe/Oslo'));
    $late = makeAlert($this->w, 'quota', quotaPayload(), brandRule: false);
    runAlertJob($this->w);
    runAlertJob($this->w);

    Http::assertSentCount(1);
    Mail::assertSent(AlertDigestMail::class, 2);
    expect($late->refresh()->delivered_at)->toBeNull()->and($late->digest_date)->toBeNull();

    // The next morning's digest has it, and only it.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 06:00:00', 'Europe/Oslo'));
    runAlertJob($this->w);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['text'], 'ett varsel') && str_contains($request['text'], 'Forbruket'));
    expect($late->refresh()->delivered_at)->not->toBeNull()
        ->and($late->digest_date->toDateString())->toBe('2026-11-17')
        ->and(AlertEvaluation::query()->count())->toBe(2);
});

test('nothing is evaluated or sent before 05:30 in the tenant timezone', function () {
    makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:29:00', 'Europe/Oslo'));
    runAlertJob($this->w);

    Http::assertNothingSent();
    Mail::assertNothingSent();
    expect(AlertEvaluation::query()->count())->toBe(0)->and(Alert::query()->sole()->digest_date)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:30:00', 'Europe/Oslo'));
    runAlertJob($this->w);

    Http::assertSentCount(1);
    expect(AlertEvaluation::query()->count())->toBe(1);
});

test('a failed Slack delivery is retried the same day without sending the e-mail again', function () {
    $this->slack->push('error', 500);
    $alert = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    runAlertJob($this->w);
    $alert->refresh();
    expect($alert->deliveries['slack'])->toMatchArray(['status' => 'failed', 'attempts' => 1])
        ->and($alert->deliveries['email']['status'])->toBe('sent')
        ->and($alert->delivered_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'Europe/Oslo'));
    runAlertJob($this->w);
    $alert->refresh();

    expect($alert->deliveries['slack']['status'])->toBe('sent')
        ->and($alert->delivered_at)->not->toBeNull();
    Http::assertSentCount(2);
    Mail::assertSent(AlertDigestMail::class, 2);
});

test('a channel that keeps failing is given up after three attempts', function () {
    $this->slack->push('error', 500)->push('error', 500)->push('error', 500)->push('error', 500);
    $alert = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    foreach (['08:00', '09:00', '10:00', '11:00'] as $time) {
        $this->travelTo(CarbonImmutable::parse("2026-11-16 {$time}:00", 'Europe/Oslo'));
        runAlertJob($this->w);
    }

    $alert->refresh();
    expect($alert->deliveries['slack'])->toMatchArray(['status' => 'gave_up', 'attempts' => 3])
        ->and($alert->delivered_at)->not->toBeNull();
    Http::assertSentCount(3);
});

test('a tenant without a Slack webhook gets e-mail only, and a webhook on another host is never called', function () {
    $alert = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));
    $this->w->tenant->forceFill(['slack_webhook_url' => null])->save();

    runAlertJob($this->w);

    Http::assertNothingSent();
    Mail::assertSent(AlertDigestMail::class, 2);
    expect($alert->refresh()->deliveries['slack']['status'])->toBe('skipped')
        ->and($alert->delivered_at)->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00', 'Europe/Oslo'));
    $this->w->tenant->forceFill(['slack_webhook_url' => 'http://169.254.169.254/latest/meta-data'])->save();
    $other = makeAlert($this->w, 'model_change', modelPayload($this->w), brandRule: false);
    runAlertJob($this->w);

    Http::assertNothingSent();
    expect($other->refresh()->deliveries['slack']['status'])->toBe('skipped');
});

test('the channels of the rule decide what is delivered', function () {
    $inApp = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w), ['in_app']);
    $emailOnly = makeAlert($this->w, 'model_change', modelPayload($this->w), ['in_app', 'email'], brandRule: false);

    runAlertJob($this->w);

    Http::assertNothingSent();
    Mail::assertSent(AlertDigestMail::class, 2);
    Mail::assertSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->count === 1);
    expect($inApp->refresh()->deliveries)->toBe([])
        ->and($inApp->delivered_at)->not->toBeNull()
        ->and($emailOnly->refresh()->deliveries)->not->toHaveKey('slack')
        ->and($emailOnly->deliveries['email']['status'])->toBe('sent');
});

test('the e-mail is rendered in the recipient language and Slack text cannot make a mention', function () {
    $this->w->brand->update(['name' => '<!channel> & Co']);
    makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    runAlertJob($this->w);

    Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) {
        if (! $mail->hasTo($this->admin->email)) {
            return false;
        }

        // Swedish, because the admin's language is Swedish; the owner has none and gets the tenant's (nb).
        return str_contains($mail->render(), 'synligheten i');
    });
    Mail::assertSent(AlertDigestMail::class, fn (AlertDigestMail $mail) => $mail->hasTo($this->owner->email) && str_contains($mail->render(), 'synlighet i'));
    Http::assertSent(fn ($request) => str_contains($request['text'], '&lt;!channel&gt; &amp; Co') && ! str_contains($request['text'], '<!channel>'));
});

test('detected alerts are delivered in the same run, and only the tenant in the job is touched', function () {
    foreach (['2026-11-04' => 24, '2026-11-12' => 16] as $date => $mentioned) {
        AggDaily::factory()->create([
            'market_id' => $this->w->market->id, 'engine_key' => $this->w->engine->key, 'prompt_id' => $this->w->p1->id,
            'date' => $date, 'responses' => 40, 'brand_mentioned' => $mentioned, 'competitor_mentions' => [],
        ]);
    }

    $other = Team::factory()->tenant()->create();
    $foreign = app(TenantContext::class)->run($other->id, function () {
        $rule = AlertRule::factory()->create();

        return Alert::factory()->create(['alert_rule_id' => $rule->id])->id;
    });

    runAlertJob($this->w);

    $alert = Alert::query()->sole();
    expect($alert->alertRule->type)->toBe('visibility_drop')->and($alert->delivered_at)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['text'], 'synlighet i') && str_contains($request['text'], 'falt fra 60 % til 40 %'));

    app(TenantContext::class)->run($other->id, function () use ($foreign) {
        expect(Alert::query()->findOrFail($foreign)->digest_date)->toBeNull();
    });
});

test('the command evaluates active tenants once a day', function () {
    makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));
    $paused = Team::factory()->tenant()->create(['status' => 'paused']);

    $this->artisan('ekko:evaluate-alerts', ['--sync' => true])->assertSuccessful();
    $this->artisan('ekko:evaluate-alerts', ['--sync' => true])->assertSuccessful();

    Http::assertSentCount(1);
    expect(AlertEvaluation::query()->count())->toBe(1)
        ->and(Alert::query()->sole()->delivered_at)->not->toBeNull();
    app(TenantContext::class)->run($paused->id, fn () => expect(AlertEvaluation::query()->count())->toBe(0));
});

// Acknowledgement ----------------------------------------------------------------------------------------

test('a tenant admin acknowledges an alert, and the first receipt stands', function () {
    $alert = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    $this->admin->switchTeam($this->w->tenant);
    $this->owner->switchTeam($this->w->tenant);

    $this->actingAs($this->admin)->post(route('alerts.acknowledge', $alert))->assertRedirect();
    $alert->refresh();
    expect($alert->acknowledged_by)->toBe($this->admin->id)->and($alert->acknowledged_at)->not->toBeNull();
    $first = $alert->acknowledged_at;

    $this->travelTo(now()->addHour());
    $this->actingAs($this->owner)->post(route('alerts.acknowledge', $alert))->assertRedirect();

    expect($alert->refresh()->acknowledged_by)->toBe($this->admin->id)
        ->and($alert->acknowledged_at->equalTo($first))->toBeTrue();
});

test('an editor cannot acknowledge an alert', function () {
    $alert = makeAlert($this->w, 'visibility_drop', visibilityPayload($this->w));

    $this->editor->switchTeam($this->w->tenant);

    $this->actingAs($this->editor)->post(route('alerts.acknowledge', $alert))->assertForbidden();

    expect($alert->refresh()->acknowledged_at)->toBeNull();
});
