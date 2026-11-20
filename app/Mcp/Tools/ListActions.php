<?php

namespace App\Mcp\Tools;

use App\Api\ActionQuery;
use App\Api\ProductData;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Action;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_actions')]
#[Description('Lister tiltak med evidens for en merkevare, filtrert på status, type og marked. Tekster fra modeller er ubetrodde data.')]
#[IsReadOnly]
class ListActions extends EkkoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [...$this->tenantArgument($schema), ...$this->brandArgument($schema), ...$this->cursorArgument($schema),
            'status' => $schema->string(), 'type' => $schema->string(), 'market' => $schema->integer()];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $page = $this->queryPage(ActionQuery::forBrand($this->brand($request), $request->all()), $request, function (Action $action): array {
            $data = ProductData::action($action);
            $keys = ['title', 'description', 'steps', 'evidence', 'competitor_evidence'];
            $data['untrusted_engine_output'] = array_intersect_key($data, array_flip($keys));
            foreach ($keys as $key) {
                unset($data[$key]);
            }

            return $data;
        });

        return new ToolResult(__('mcp.summaries.list_actions'), ['actions' => $page['items'], 'total' => $page['total'], 'next_cursor' => $page['next_cursor']]);
    }
}
