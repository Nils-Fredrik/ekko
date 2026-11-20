<?php

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\ModelChange;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

// Loaded only by seed.php after its dedicated-test-database guard. U10: a partner with four clients for the
// portfolio (falling, rising, steady and one without data), and the falling client's overview timeline.
$portfolioPartner = Team::factory()->tenant()->create(['name' => 'Portfolio partner']);
$portfolioAdvisor = User::factory()->withTwoFactor()->create([
    'email' => 'browser-portfolio@example.test', 'password' => Hash::make($password), 'locale' => 'en',
]);
$portfolioPartner->users()->attach($portfolioAdvisor, ['role' => 'partner_advisor']);
$portfolioAdvisor->switchTeam($portfolioPartner);
$portfolioEngine = Engine::factory()->create(['key' => 'portfolio_engine', 'provider' => 'openai']);
$context = app(TenantContext::class);

// [name, mentioned of 20 now, mentioned of 20 in the previous period]; null means no data at all.
foreach ([['Portfolio Rising', 14, 10], ['Portfolio Falling', 4, 16], ['Portfolio Steady', 10, 10], ['Portfolio Empty', null, null]] as [$name, $now, $before]) {
    $client = Team::factory()->tenant()->create(['name' => $name, 'partner_team_id' => $portfolioPartner->id, 'timezone' => 'UTC']);
    $context->run($client->id, function () use ($name, $now, $before, $portfolioEngine): void {
        $brand = Brand::factory()->create(['name' => $name.' brand']);
        $market = Market::factory()->for($brand)->create();
        $prompt = Prompt::factory()->for($market)->create();
        if ($now === null) {
            return;
        }
        foreach ([[now('UTC')->subDays(3), $now], [now('UTC')->subDays(35), $before]] as [$date, $mentioned]) {
            AggDaily::factory()->create([
                'market_id' => $market->id,
                'brand_id' => $brand->id,
                'engine_key' => $portfolioEngine->key,
                'prompt_id' => $prompt->id,
                'date' => $date->toDateString(),
                'responses' => 20,
                'brand_mentioned' => $mentioned,
            ]);
        }
        if ($name !== 'Portfolio Falling') {
            return;
        }
        Alert::factory()->create([
            'alert_rule_id' => AlertRule::factory()->create(['brand_id' => $brand->id])->id,
            'triggered_at' => now()->subDay(),
        ]);
        Action::factory()->create(['brand_id' => $brand->id, 'status' => ActionStatus::New, 'title' => 'Open portfolio action']);
        Action::factory()->create(['brand_id' => $brand->id, 'status' => ActionStatus::Done, 'title' => 'Finished portfolio action',
            'completed_at' => now()->subDays(2)]);
        Competitor::factory()->for($brand)->create(['name' => 'Fresh competitor', 'created_at' => now()->subDays(4)]);
        ModelChange::factory()->create(['engine_key' => $portfolioEngine->key, 'changed_on' => now('UTC')->subDays(5)->toDateString(),
            'from_model' => 'portfolio-model-1', 'to_model' => 'portfolio-model-2']);
    });
}

return ['SMOKE_PORTFOLIO_EMAIL' => $portfolioAdvisor->email];
