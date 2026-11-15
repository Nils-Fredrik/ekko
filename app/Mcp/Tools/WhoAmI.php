<?php

namespace App\Mcp\Tools;

use App\Enums\TenantPermission;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Hvem du er i Ekko: navn, tenantene Claude kan bruke (tenanten valgt ved tilkobling og, for partnerrådgivere, kunde-tenantene), rollen og rettighetene i hver, og om Claude kan gjøre endringer. Bruk dette først når du er usikker på hva brukeren kan se.')]
#[IsReadOnly]
class WhoAmI extends EkkoTool
{
    protected ?TenantPermission $permission = null;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->tenantArgument($schema);
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $default = $context->defaultTenant();
        $tenants = $context->tenants()->map(function (Team $team) use ($context, $default): array {
            $role = $context->role($team);

            return [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $role?->value,
                'permissions' => $role === null ? [] : array_map(fn (TenantPermission $p): string => $p->value, $role->permissions()),
                'chosen_when_connecting' => $team->id === $default?->id,
                'client_of_partner' => $team->partner_team_id !== null && $team->id !== $default?->id,
            ];
        })->values()->all();

        return new ToolResult(
            __('mcp.summaries.whoami', ['name' => $context->user->name, 'count' => count($tenants)]),
            [
                'user' => ['name' => $context->user->name, 'email' => $context->user->email],
                'can_write' => $context->canWrite(),
                'tenants' => $tenants,
            ],
        );
    }
}
