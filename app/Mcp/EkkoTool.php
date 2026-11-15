<?php

namespace App\Mcp;

use App\Enums\TenantPermission;
use App\Metrics\MetricsFilter;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;

/**
 * The common part of Ekko's MCP tools (docs/spec.md 15.2, ADR 0016):
 * - the call works in one tenant: the one chosen when connecting, or a client tenant a partner advisor names
 *   with the argument "tenant"; TenantAccess decides, as on the pages
 * - the tool runs with that tenant in the tenant context and under row-level security for that tenant only
 * - the role must have the tool's permission there, and a writing tool needs a connection that allows changes
 * - the answer is structured JSON with a short text summary; errors are short messages for Claude
 */
abstract class EkkoTool extends Tool
{
    /** The permission the role needs in the tenant (null: any role with access). */
    protected ?TenantPermission $permission = TenantPermission::ViewData;

    /** The tool changes something: needs a connection that allows changes. */
    protected bool $writes = false;

    abstract protected function run(Request $request, McpContext $context, Team $tenant): ToolResult;

    public function permission(): ?TenantPermission
    {
        return $this->permission;
    }

    /** Writing tools are only listed for connections that allow changes. */
    public function shouldRegister(): bool
    {
        $context = McpContext::current();

        return $context !== null && (! $this->writes || $context->canWrite());
    }

    public function handle(Request $request, TenantContext $tenantContext, TenantRls $rls): Response|ResponseFactory
    {
        $context = McpContext::current();
        if ($context === null) {
            return Response::error(__('mcp.errors.reconnect'));
        }
        if ($this->writes && ! $context->canWrite()) {
            return Response::error(__('mcp.errors.read_only'));
        }
        $tenant = $context->tenant($request->get('tenant'));
        if ($tenant === null) {
            return Response::error(__('mcp.errors.unknown_tenant'));
        }
        if ($this->permission !== null && ! $context->can($tenant, $this->permission)) {
            return Response::error(__('mcp.errors.forbidden'));
        }

        try {
            $result = $tenantContext->run($tenant->id, fn (): ToolResult => $rls->run(
                [$tenant->id],
                fn (): ToolResult => $this->run($request, $context, $tenant),
            ));
        } catch (ToolFailure $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            return Response::error(ValidationMessages::from($e));
        } catch (ModelNotFoundException) {
            return Response::error(__('mcp.errors.not_found'));
        } catch (InvalidArgumentException $e) {
            return Response::error(__('mcp.errors.invalid', ['message' => $e->getMessage()]));
        }

        $data = ['tenant' => ['id' => $tenant->id, 'name' => $tenant->name], ...$result->data];
        $structured = Response::structured($data)->responses()->all();

        return (new ResponseFactory([Response::text($result->summary), ...$structured]))->withStructuredContent($data);
    }

    /**
     * The argument every tool has.
     *
     * @return array<string, mixed>
     */
    protected function tenantArgument(JsonSchema $schema): array
    {
        return ['tenant' => $schema->integer()->description('Tenant-id. Standard: tenanten valgt da Claude ble koblet til. Partnerrådgivere kan angi en kunde-tenant. Se whoami.')];
    }

    /** @return array<string, mixed> */
    protected function brandArgument(JsonSchema $schema): array
    {
        return ['brand' => $schema->integer()->description('Merkevare-id (se list_brands). Kan utelates når tenanten har én merkevare.')];
    }

    /** @return array<string, mixed> */
    protected function periodArguments(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Første dag (ÅÅÅÅ-MM-DD), tenantens lokale dag. Standard: 30 dager før to.'),
            'to' => $schema->string()->description('Siste dag (ÅÅÅÅ-MM-DD), tatt med. Standard: i dag.'),
        ];
    }

    /** @return array<string, mixed> */
    protected function marketEngineArguments(JsonSchema $schema): array
    {
        return [
            'markets' => $schema->array()->items($schema->integer())->description('Marked-id-er (se list_brands). Tom: alle.'),
            'engines' => $schema->array()->items($schema->string())->description('Motornøkler, f.eks. chatgpt_api, gemini_api, perplexity_api, claude_haiku. Tom: alle.'),
        ];
    }

    /** @return array<string, mixed> */
    protected function cursorArgument(JsonSchema $schema): array
    {
        return ['cursor' => $schema->string()->description('next_cursor fra forrige side.')];
    }

    /** The brand of the call: the argument, or the only brand of the tenant. */
    protected function brand(Request $request): Brand
    {
        $id = $request->get('brand');
        if ($id !== null && $id !== '') {
            if (! is_numeric($id)) {
                throw new ToolFailure(__('mcp.errors.unknown_brand'));
            }

            return Brand::query()->find((int) $id) ?? throw new ToolFailure(__('mcp.errors.unknown_brand'));
        }

        $brands = Brand::query()->orderBy('id')->limit(2)->get();
        if ($brands->count() !== 1) {
            throw new ToolFailure(__($brands->isEmpty() ? 'mcp.errors.no_brand' : 'mcp.errors.choose_brand'));
        }

        return $brands->first();
    }

    /**
     * The period of the call in the tenant's days: from and to, both included, at most 400 days.
     *
     * @return array{0: string, 1: string}
     */
    protected function period(Request $request, Team $tenant, int $defaultDays = 30): array
    {
        $timezone = $this->timezone($tenant);
        $to = $this->date($request->get('to')) ?? CarbonImmutable::now($timezone)->startOfDay();
        $from = $this->date($request->get('from')) ?? $to->subDays($defaultDays - 1);
        if ($from->gt($to) || $from->diffInDays($to) > 400) {
            throw new ToolFailure(__('mcp.errors.period'));
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * The metrics filter: brand, period, markets and engines (docs/spec.md 9).
     *
     * @param  array{0: string, 1: string}|null  $period
     * @param  array{topicIds?: list<int>, intents?: list<string>, funnelStages?: list<string>, promptIds?: list<int>}  $extra
     */
    protected function filter(Request $request, Team $tenant, Brand $brand, ?array $period = null, array $extra = []): MetricsFilter
    {
        [$from, $to] = $period ?? $this->period($request, $tenant);

        return new MetricsFilter(
            $brand->id, $from, $to,
            marketIds: $this->marketIds($request, $brand),
            engineKeys: $this->stringList($request->get('engines')),
            topicIds: $extra['topicIds'] ?? [],
            intents: $extra['intents'] ?? [],
            funnelStages: $extra['funnelStages'] ?? [],
            promptIds: $extra['promptIds'] ?? [],
        );
    }

    /**
     * The markets in the argument, which must belong to the brand.
     *
     * @return list<int>
     */
    protected function marketIds(Request $request, Brand $brand): array
    {
        $ids = $this->intList($request->get('markets'));
        if ($ids === []) {
            return [];
        }
        $found = Market::query()->where('brand_id', $brand->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($found) !== count(array_unique($ids))) {
            throw new ToolFailure(__('mcp.errors.unknown_market'));
        }

        return $found;
    }

    /**
     * One page of a list and the cursor for the next.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return array{items: list<T>, next_cursor: string|null, total: int}
     */
    protected function page(array $items, Request $request, ?int $size = null): array
    {
        $size ??= max(1, (int) config('ekko.mcp.page_size'));
        $offset = $this->offset($request);

        return [
            'items' => array_slice($items, $offset, $size),
            'next_cursor' => $offset + $size < count($items) ? $this->cursor($offset + $size) : null,
            'total' => count($items),
        ];
    }

    /** The offset in the cursor argument (0 without one). */
    protected function offset(Request $request): int
    {
        $cursor = $request->get('cursor');
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = is_string($cursor) ? json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true) : null;
        if (! is_array($decoded) || ! is_int($decoded['o'] ?? null) || $decoded['o'] < 0) {
            throw new ToolFailure(__('mcp.errors.cursor'));
        }

        return $decoded['o'];
    }

    /** The tenant's timezone, where its days begin and end. */
    protected function timezone(Team $tenant): string
    {
        return $tenant->timezone !== '' ? $tenant->timezone : 'UTC';
    }

    /** A point in time as ISO 8601, in the given timezone. */
    protected function isoTime(mixed $value, ?string $timezone = null): ?string
    {
        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return ($timezone === null ? $value : $value->copy()->setTimezone($timezone))->toIso8601String();
    }

    /** A rate (0–1) as a percentage for the text summary. */
    protected function percent(?float $value): string
    {
        return $value === null ? '–' : number_format($value * 100, 1, ',', ' ').' %';
    }

    protected function cursor(int $offset): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['o' => $offset])), '+/', '-_'), '=');
    }

    /** @return list<int> */
    protected function intList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (! is_array($value)) {
            $value = [$value];
        }
        $ids = [];
        foreach ($value as $item) {
            if (! is_numeric($item)) {
                throw new ToolFailure(__('mcp.errors.invalid', ['message' => (string) json_encode($value)]));
            }
            $ids[] = (int) $item;
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    protected function stringList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($item): string => (string) $item, is_array($value) ? $value : [$value])));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new ToolFailure(__('mcp.errors.date', ['value' => is_scalar($value) ? (string) $value : '?']));
        }

        return $date;
    }
}
