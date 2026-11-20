<?php

namespace App\Mcp\Tools;

use App\Api\ProductData;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Team;
use App\Pitch\PitchProvisioner;
use App\Tenancy\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_pitch')]
#[Description('Oppretter et sju dagers pitch-prosjekt med domene og marked under valgt partner. Bare partnerrådgiver eller plattformadmin med skriverett. Kvoten håndheves og opprettelsen logges. Oppretter ikke prompts og gjør ingen betalte modellkall; legg til prompts og aktiver motorer i Ekko før kjøring. Bruk bare på brukerens beskjed.')]
class CreatePitch extends EkkoTool
{
    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->tenantArgument($schema), 'name' => $schema->string()->required(),
            'domain' => $schema->string()->required(), 'country' => $schema->string()->required(), 'language' => $schema->string()->required()];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $pitch = app(PitchProvisioner::class)->create($context->user, $tenant, $request->all());
        app(AuditLog::class)->mcpChange($context->user, $tenant, $this->name(), ['pitch_id' => $pitch->id, 'mcp_connection_id' => $context->connection?->id]);

        return new ToolResult(__('mcp.summaries.create_pitch'), ['pitch' => ProductData::pitch($pitch)]);
    }
}
