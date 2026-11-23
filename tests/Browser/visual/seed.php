<?php

// Included only by the guarded browser fixture entry point. No live identities or external calls.
use App\Metrics\DailyAggregator;
use App\Models\Action;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use App\Models\Competitor;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Mention;
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
EnginePrice::factory()->for($engine)->create(['valid_from' => now()->addDay(), 'price_per_call_usd' => '0.02460001']);
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
        BotHitDaily::query()->create(['brand_id' => $brand->id, 'domain' => 'coffee.example.test', 'bot' => 'GPTBot', 'date' => $day, 'hits' => 35, 'unique_paths' => 4, 'top_paths' => [['path' => '/', 'hits' => 20], ['path' => '/sourcing', 'hits' => 15]]]);
        AiReferralDaily::query()->create(['brand_id' => $brand->id, 'source' => 'chatgpt.com', 'date' => $day, 'sessions' => 12, 'engaged_sessions' => 8, 'conversions' => 2]);
    }
    $action = Action::factory()->own()->for($brand)->create(['market_id' => $market->id, 'topic_id' => $topic->id,
        'title' => 'Clarify the coffee sourcing page', 'description' => 'Publish a concise explanation of the sourcing policy.',
        'steps' => [['text' => 'Review the current page', 'done' => true], ['text' => 'Add source references', 'done' => false]]]);
    $values['UI_PROMPT_ID'] = (string) $world->p1->id;
    $values['UI_ACTION_ID'] = (string) $action->id;
    $fact = BrandFact::factory()->for($brand)->create(['text' => 'Founded in 1998.', 'is_active' => true]);
    BrandFact::factory()->for($brand)->create(['text' => 'Coffee is roasted in Oslo.', 'is_active' => false]);
    $response->update(['answer_text' => 'Fjord Coffee was founded in 2005. Northern Roast is another option.']);
    Claim::query()->create(['brand_id' => $brand->id, 'response_id' => $response->id, 'fact_id' => $fact->id,
        'text' => 'Founded in 2005.', 'quote' => 'Fjord Coffee was founded in 2005.', 'verdict' => Claim::CONTRADICTED,
        'source_citation_ids' => $response->citations()->pluck('id')->all()]);
    // The guest scenario opens the English report and increments its link's view count.
    $values['UI_REPORT_ID'] = (string) Report::query()->whereHas('reportSchedule', fn ($query) => $query->where('locale', 'nb'))->firstOrFail()->id;
    // U29: the newest positive brand mention has the model's quote for its sentiment, so the answer list shows it.
    $quoted = Mention::query()->select('mentions.*')->join('responses', 'responses.id', '=', 'mentions.response_id')
        ->where('mentions.entity_type', 'brand')->where('mentions.entity_id', $brand->id)->where('mentions.sentiment', '>', 0)
        ->orderByDesc('responses.collected_at')->orderByDesc('mentions.id')->with('response')->first();
    if ($quoted !== null) {
        $answerText = (string) $quoted->response?->answer_text;
        $quoted->update(['sentiment_quote' => mb_substr($answerText, 0, min(90, mb_strlen($answerText)))]);
    }
    // U22: a brand with unknown names waiting for a decision, for Setup › Competitors and brands. The sidebar of the
    // other brands is not affected, because the count is per brand.
    $triageBrand = Brand::factory()->create(['name' => 'Fjord Tea', 'primary_domain' => 'tea.example.test']);
    $triageMarket = Market::factory()->for($triageBrand)->create(['country' => 'NO', 'language' => 'nb']);
    $triageMarket->marketEngines()->create(['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]);
    $triage = new MetricsWorld;
    $triage->tenant = $team;
    $triage->brand = $triageBrand;
    $triage->market = $triageMarket;
    $triage->engine = $engine;
    $triageCompetitor = Competitor::factory()->for($triageBrand)->create(['name' => 'Harbour Tea']);
    $triagePrompt = Prompt::factory()->for($triageMarket)->create(['text' => 'Which tea shops do you recommend?']);
    foreach ([['Havnelys Studio', 6], ['Shopify', 5], ['Harbour Tea House', 4], ['Beste Byrå', 2]] as [$name, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $reply = $triage->answer($triagePrompt, '2026-10-14T12:00:00Z', ['unknown' => $name]);
            $reply->update(['answer_text' => "For tea, many people recommend {$name} as an alternative to the big chains in town."]);
            $reply->mentions()->where('entity_type', 'unknown')->update(['first_char_offset' => strlen('For tea, many people recommend ')]);
        }
    }
    $values['UI_TRIAGE_BRAND_ID'] = (string) $triageBrand->id;
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
