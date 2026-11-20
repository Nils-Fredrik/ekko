<?php

// Included only by the guarded browser fixture entry point. No live identities or external calls.
use App\Metrics\DailyAggregator;
use App\Models\Action;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Metrics\MetricsWorld;

$team->forceFill(['name' => 'Fjord Coffee', 'timezone' => 'UTC'])->save();
$user->forceFill(['name' => 'Alex Reader'])->save();
$owner->forceFill(['name' => 'Robin Owner'])->save();
$billingAdmin->forceFill(['name' => 'Taylor Admin'])->save();
$values['UI_OWNER_EMAIL'] = $owner->email;
$values['UI_ADMIN_EMAIL'] = $billingAdmin->email;
app(TenantContext::class)->run($team->id, function () use ($team, $brand, $engine, &$values): void {
    $brand->update(['name' => 'Fjord Coffee', 'primary_domain' => 'coffee.example.test']);
    $brand->domains()->create(['domain' => 'coffee.example.test', 'include_subdomains' => true]);
    $brand->aliases()->create(['alias' => 'Fjord Coffee', 'match_mode' => 'case_insensitive']);
    $market = Market::factory()->for($brand)->create(['country' => 'NO', 'language' => 'nb']);
    $market->marketEngines()->create(['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]);
    $topic = Topic::factory()->for($brand)->create(['name' => 'Coffee shops', 'cross_market_key' => 'coffee']);
    $world = new MetricsWorld;
    $world->tenant = $team;
    $world->brand = $brand;
    $world->market = $market;
    $world->engine = $engine;
    $world->c1 = Competitor::factory()->for($brand)->create(['name' => 'Northern Roast']);
    $world->c2 = Competitor::factory()->for($brand)->create(['name' => 'Harbour Coffee']);
    $world->p1 = Prompt::factory()->for($market)->create(['topic_id' => $topic->id, 'text' => 'Where can I find sustainably roasted coffee?']);
    $world->p2 = Prompt::factory()->for($market)->create(['topic_id' => $topic->id, 'text' => 'Compare coffee shops in Oslo.']);
    foreach (['2026-10-10', '2026-10-12', '2026-10-14'] as $day) {
        foreach (range(1, 10) as $i) {
            $response = $world->answer($i % 2 ? $world->p1 : $world->p2, $day.'T12:00:00Z', [
                'brand' => ['pos' => 1, 'rec' => true, 'sent' => 0.7],
                'competitors' => [$world->c1->id => 2], 'citations' => [['coffee.example.test', 'own'], ['guide.example.test', 'third_party']],
            ]);
            $response->update(['answer_text' => 'Fjord Coffee offers sustainably roasted coffee. Northern Roast is another option.']);
        }
        app(DailyAggregator::class)->aggregate($day);
        BotHitDaily::query()->create(['brand_id' => $brand->id, 'domain' => 'coffee.example.test', 'bot' => 'GPTBot', 'date' => $day, 'hits' => 35, 'unique_paths' => 4, 'top_paths' => []]);
        AiReferralDaily::query()->create(['brand_id' => $brand->id, 'source' => 'chatgpt.com', 'date' => $day, 'sessions' => 12, 'engaged_sessions' => 8, 'conversions' => 2]);
    }
    $action = Action::factory()->own()->for($brand)->create(['market_id' => $market->id, 'topic_id' => $topic->id,
        'title' => 'Clarify the coffee sourcing page', 'description' => 'Publish a concise explanation of the sourcing policy.',
        'steps' => [['text' => 'Review the current page', 'done' => true], ['text' => 'Add source references', 'done' => false]]]);
    $values['UI_PROMPT_ID'] = (string) $world->p1->id;
    $values['UI_ACTION_ID'] = (string) $action->id;
    $values['UI_REPORT_ID'] = (string) Report::query()->orderBy('id')->firstOrFail()->id;
    $values['UI_EMPTY_BRAND_ID'] = (string) Brand::factory()->create(['name' => 'New brand', 'primary_domain' => 'new.example.test'])->id;
});
$advisor = User::factory()->withTwoFactor()->create(['name' => 'Jamie Advisor', 'email' => 'ui-advisor@example.test', 'password' => Hash::make($password), 'locale' => 'en']);
$team->users()->attach($advisor, ['role' => 'partner_advisor']);
$advisor->switchTeam($team);
$values['UI_ADVISOR_EMAIL'] = $advisor->email;
$pitch = Team::factory()->clientOf($team)->create(['name' => 'Coffee pitch', 'kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)]);
$pitchUser = User::factory()->create(['name' => 'Chris Pitch', 'email' => 'ui-pitch@example.test', 'password' => Hash::make($password), 'locale' => 'en']);
$pitch->users()->attach($pitchUser, ['role' => 'reader']);
$pitchUser->switchTeam($pitch);
$values['UI_PITCH_EMAIL'] = $pitchUser->email;
$values['UI_PITCH_BRAND_ID'] = (string) app(TenantContext::class)->run($pitch->id, fn () => Brand::factory()->create(['name' => 'Coffee pitch brand'])->id);
$unverified = User::factory()->unverified()->create(['name' => 'Morgan Pending', 'email' => 'ui-pending@example.test', 'password' => Hash::make($password), 'locale' => 'en']);
$team->users()->attach($unverified, ['role' => 'reader']);
$unverified->switchTeam($team);
$values['UI_UNVERIFIED_EMAIL'] = $unverified->email;
$challenge = User::factory()->withTwoFactor()->create(['name' => 'Casey Secure', 'email' => 'ui-challenge@example.test', 'password' => Hash::make($password), 'locale' => 'en']);
$team->users()->attach($challenge, ['role' => 'reader']);
$challenge->switchTeam($team);
$values['UI_CHALLENGE_EMAIL'] = $challenge->email;
