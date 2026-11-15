<?php

use App\Enums\TenantPermission;
use App\Mcp\Tools;

/*
|--------------------------------------------------------------------------
| MCP tool register (docs/spec.md 15.2, T34)
|--------------------------------------------------------------------------
|
| Every class in app/Mcp/Tools, with the permission it needs in the tenant (App\Enums\TenantPermission, null
| for any role with access) and the arguments for a call, built from the ids of one tenant's data
| (brand, market, prompt, topic). A tool without an entry fails CodebaseGuardsTest.
|
| McpToolIsolationTest calls every tool as every role of tenant A: with A's ids (allowed or refused by the
| permission), with B's ids in A, and with B named as the tenant. Nothing from B may show up, and nothing in B
| may change.
|
*/

$period = ['from' => now('Europe/Oslo')->subDays(7)->format('Y-m-d'), 'to' => now('Europe/Oslo')->format('Y-m-d')];

return [
    Tools\WhoAmI::class => ['permission' => null, 'arguments' => fn (array $ids) => []],
    Tools\ListBrands::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => []],
    Tools\GetVisibility::class => [
        'permission' => TenantPermission::ViewData,
        'arguments' => fn (array $ids) => ['brand' => $ids['brand'], 'markets' => [$ids['market']], 'topics' => [$ids['topic']], 'group_by' => 'prompt', ...$period],
    ],
    Tools\CompareCompetitors::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['brand' => $ids['brand'], ...$period]],
    Tools\GetPromptResults::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['brand' => $ids['brand'], ...$period]],
    Tools\GetResponses::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['prompt' => $ids['prompt'], ...$period]],
    Tools\GetSources::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['brand' => $ids['brand'], ...$period]],
    Tools\GetCrawlerActivity::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['brand' => $ids['brand'], ...$period]],
    Tools\ListAlerts::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['brand' => $ids['brand']]],
    Tools\GetUsage::class => ['permission' => TenantPermission::ViewUsage, 'arguments' => fn (array $ids) => []],
    Tools\ProposePrompts::class => ['permission' => TenantPermission::ViewData, 'arguments' => fn (array $ids) => ['market' => $ids['market'], 'topic' => $ids['topic']]],
    Tools\UpsertPrompts::class => [
        'permission' => TenantPermission::ManagePrompts,
        'arguments' => fn (array $ids) => ['prompts' => [
            ['id' => $ids['prompt'], 'is_active' => false],
            ['market' => $ids['market'], 'topic' => $ids['topic'], 'text' => 'Isolation test prompt '.$ids['prompt'], 'intent' => 'category', 'funnel_stage' => 'awareness'],
        ]],
    ],
];
