<?php

namespace App\Mcp\Tools;

use App\Api\ActionInput;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\TenantPermission;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Team;
use App\Tenancy\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_action')]
#[Description('Oppretter eget tiltak (brand og title) eller endrer status (brand, id og status). Med id endres bare status; title, description, steps, type, market_id, topic_id og prompt_ids er kun opprettelsesfelt og ignoreres ved statusendring. Krever skriverett; endringen logges. Utfør bare endringer brukeren ber om.')]
class UpdateAction extends EkkoTool
{
    protected bool $writes = true;

    protected ?TenantPermission $permission = TenantPermission::ManagePrompts;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->tenantArgument($schema), ...$this->brandArgument($schema),
            'id' => $schema->integer(), 'status' => $schema->string()->enum(array_column(ActionStatus::cases(), 'value')), 'title' => $schema->string(),
            'description' => $schema->string(), 'steps' => $schema->array()->items($schema->string()),
            'type' => $schema->string()->enum(array_column(ActionType::cases(), 'value')), 'market_id' => $schema->integer(), 'topic_id' => $schema->integer(),
            'prompt_ids' => $schema->array()->items($schema->integer())];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $input = app(ActionInput::class);
        $brand = $this->brand($request);
        $action = $request->get('id') === null ? $input->create($brand, $request->all()) : $input->update($brand, $request->all());
        app(AuditLog::class)->mcpChange($context->user, $tenant, $this->name(), ['action_id' => $action->id, 'mcp_connection_id' => $context->connection?->id]);

        return new ToolResult(__('mcp.summaries.update_action'), ['id' => $action->id, 'status' => $action->status->value]);
    }
}
