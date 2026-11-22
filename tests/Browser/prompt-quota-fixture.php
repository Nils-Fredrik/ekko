<?php

use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

// Loaded only by seed.php after its dedicated-test-database guard. U20: a plan with room for two
// active prompts per market. The first market is full, so «New prompt» is disabled there; the
// second has room, so N opens a new row.
$quotaTeam = Team::factory()->tenant()->withPlan(Plan::factory()->create(['max_prompts_per_market' => 2]))->create();
$quotaUser = $quotaTeam->owner;
$quotaUser->forceFill(['email' => 'browser-prompt-quota@example.test', 'password' => Hash::make($password), 'locale' => 'en'])->save();
$quotaUser->switchTeam($quotaTeam);

return app(TenantContext::class)->run($quotaTeam->id, function () use ($quotaUser): array {
    $brand = Brand::factory()->create(['name' => 'Prompt quota fixture']);
    $full = Market::factory()->create(['brand_id' => $brand->id, 'country' => 'NO', 'location_label' => 'NO']);
    $open = Market::factory()->create(['brand_id' => $brand->id, 'country' => 'SE', 'location_label' => 'SE']);
    foreach (['Quota prompt A', 'Quota prompt B'] as $text) {
        Prompt::factory()->create(['market_id' => $full->id, 'topic_id' => null, 'text' => $text, 'is_active' => true]);
    }
    Prompt::factory()->create(['market_id' => $open->id, 'topic_id' => null, 'text' => 'Quota prompt C', 'is_active' => true]);

    return ['QUOTA_EMAIL' => $quotaUser->email, 'QUOTA_BRAND_ID' => (string) $brand->id,
        'QUOTA_FULL_MARKET_ID' => (string) $full->id, 'QUOTA_OPEN_MARKET_ID' => (string) $open->id];
});
