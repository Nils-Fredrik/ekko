<?php

use App\Jobs\EvaluateTenantAlerts;
use App\Mail\AlertDigestMail;
use App\Models\Alert;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Metrics\MetricsWorld;

test('pages, email and slack hide internal alert keys', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $world = MetricsWorld::create();
    $world->tenant->forceFill(['slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXXXXXX'])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $hash = str_repeat('ab', 16);
    $rule = AlertRule::factory()->create([
        'brand_id' => $world->brand->id,
        'type' => 'new_competitor',
        'channels' => ['in_app', 'email', 'slack'],
        'is_active' => true,
    ]);
    Alert::factory()->create([
        'alert_rule_id' => $rule->id,
        'subject' => "market:{$world->market->id}:name:{$hash}",
        'payload' => [
            'name' => 'Harbour Coffee',
            'market_id' => $world->market->id,
            'share' => ['value' => 0.2, 'n' => 40],
        ],
    ]);
    $promptRule = AlertRule::factory()->create([
        'brand_id' => $world->brand->id,
        'type' => 'competitor_takeover',
        'channels' => ['in_app', 'email', 'slack'],
        'is_active' => true,
    ]);
    Alert::factory()->create([
        'alert_rule_id' => $promptRule->id,
        'subject' => "prompt:{$world->p1->id}:competitor:{$world->c1->id}",
        'payload' => [
            'competitor_id' => $world->c1->id,
            'prompt_id' => $world->p1->id,
            'previous' => ['value' => 0.2, 'n' => 10],
            'current' => ['value' => 0.5, 'n' => 12],
        ],
    ]);

    Mail::fake();
    Http::fake(['https://hooks.slack.com/*' => Http::response('ok', 200)]);
    $this->actingAs($owner)->get(route('alerts.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->missing('alerts.0.subject')
        ->missing('alerts.1.subject'));

    $html = $this->get(route('alerts.index'))->assertOk()->getContent();
    expect($html)->not->toContain('market:')->and($html)->not->toContain('prompt:')->and($html)->not->toContain($hash);

    dispatch_sync(new EvaluateTenantAlerts($world->tenant->id));

    Http::assertSent(function ($request) use ($hash) {
        $text = (string) $request['text'];
        expect($text)->not->toContain('market:')->and($text)->not->toContain('prompt:')->and($text)->not->toContain($hash);

        return true;
    });
    Mail::assertSent(AlertDigestMail::class, function (AlertDigestMail $mail) use ($hash) {
        $rendered = $mail->render();
        expect($rendered)->not->toContain('market:')->and($rendered)->not->toContain('prompt:')->and($rendered)->not->toContain($hash);

        return true;
    });
});
