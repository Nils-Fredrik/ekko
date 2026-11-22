<?php

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

// Loaded only by seed.php after its dedicated-test-database guard. U22: a tenant with 50 unknown names that each
// occur in three answers (the 2-minute test of Setup › Competitors and brands), a few spelling variants, and three
// competitors for the drag-and-drop test. Names, brands and answers are synthetic.
$triageTeam = Team::factory()->tenant()->create();
$triageUser = $triageTeam->owner;
$triageUser->forceFill(['email' => 'browser-triage@example.test', 'password' => Hash::make($password), 'locale' => 'en'])->save();
$triageUser->switchTeam($triageTeam);

return app(TenantContext::class)->run($triageTeam->id, function () use ($triageUser): array {
    $brand = Brand::factory()->create(['name' => 'Triage fixture']);
    $market = Market::factory()->create(['brand_id' => $brand->id, 'country' => 'NO', 'location_label' => 'NO']);
    $engine = Engine::query()->where('key', 'browser_triage')->first() ?? Engine::factory()->create(['key' => 'browser_triage']);
    $prompt = Prompt::factory()->create(['market_id' => $market->id, 'topic_id' => null, 'text' => 'Which studios do you recommend?']);
    foreach (['Rival Alpha', 'Rival Beta', 'Rival Gamma'] as $name) {
        Competitor::factory()->for($brand)->create(['name' => $name]);
    }

    for ($n = 1; $n <= 50; $n++) {
        $name = sprintf('Synthetic Name %02d', $n);
        for ($i = 0; $i < 3; $i++) {
            $batch = RunBatch::factory()->create(['market_id' => $market->id]);
            $run = Run::factory()->create(['run_batch_id' => $batch->id, 'prompt_id' => $prompt->id, 'engine_key' => $engine->key, 'status' => 'extracted']);
            $response = Response::factory()->create([
                'run_id' => $run->id, 'engine_key' => $engine->key, 'collected_at' => now()->subHours(2 + $i),
                'answer_text' => "Many people recommend {$name} for larger projects, together with other studios in the region.",
            ]);
            Mention::factory()->create([
                'response_id' => $response->id, 'entity_type' => 'unknown', 'detection' => 'llm', 'surface_name' => $name,
                'first_char_offset' => strlen('Many people recommend '),
            ]);
        }
    }

    return ['TRIAGE_EMAIL' => $triageUser->email, 'TRIAGE_BRAND_ID' => (string) $brand->id];
});
