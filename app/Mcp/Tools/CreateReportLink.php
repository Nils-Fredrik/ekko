<?php

namespace App\Mcp\Tools;

use App\Enums\TenantPermission;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Report;
use App\Models\Team;
use App\Reports\ReportLinks;
use App\Tenancy\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_report_link')]
#[Description('Oppretter en tidsbegrenset delbar rapportlenke. Krever skriverett og rett til å administrere rapporter; logges uten lenkehemmeligheten. Lenken gir lesetilgang uten innlogging og skal bare lages når brukeren ber om deling. Hemmeligheten vises én gang.')]
class CreateReportLink extends EkkoTool
{
    protected bool $writes = true;

    protected ?TenantPermission $permission = TenantPermission::ManageReports;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->tenantArgument($schema), 'report' => $schema->integer()->required(),
            'days' => $schema->integer()->description('Levetid 1–3650 dager, standard 90.')];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $data = $request->validate(['report' => ['required', 'integer', 'min:1'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650']]);
        $report = Report::query()->findOrFail($data['report']);
        $link = app(ReportLinks::class)->create($context->user, $report, (int) ($data['days'] ?? 90));
        app(AuditLog::class)->mcpChange($context->user, $tenant, $this->name(), ['report_id' => $report->id, 'link_id' => $link['id'], 'mcp_connection_id' => $context->connection?->id]);

        return new ToolResult(__('mcp.summaries.create_report_link'), ['link' => $link]);
    }
}
