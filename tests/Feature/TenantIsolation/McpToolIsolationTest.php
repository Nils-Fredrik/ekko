<?php

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Mcp\EkkoTool;
use App\Mcp\Servers\EkkoServer;
use Tests\Feature\TenantIsolation\Support\McpData;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\Scenario;
use Tests\Support\Mcp\McpTestClient;

// docs/spec.md 5.3 and 15.2 (T34, ADR 0005): generated from the MCP tool register, so every tool is checked.
// No tool may return data from another tenant or change anything in it, whatever the role and the arguments,
// and the role decides what each tool may do in the user's own tenant.

/**
 * @return array<string, array{0: class-string<EkkoTool>}>
 */
function mcpToolKeys(): array
{
    $register = require __DIR__.'/mcp-tools.php';
    $keys = array_keys($register);

    return array_combine(array_map(fn (string $class) => class_basename($class), $keys), array_map(fn ($key) => [$key], $keys));
}

/**
 * @return array{permission: TenantPermission|null, allowed_roles?: list<string>, client_allowed?: bool, arguments: Closure(array<string, int>): array<string, mixed>}
 */
function mcpToolEntry(string $class): array
{
    return (require __DIR__.'/mcp-tools.php')[$class];
}

function mcpToolName(string $class): string
{
    return app($class)->name();
}

beforeEach(function () {
    $this->s = Scenario::create();
    $this->idsA = McpData::seed($this->s->tenantA, 'TENANT-A');
    $this->idsClient = McpData::seed($this->s->clientOfA, 'CLIENT-OF-A');
    $this->idsB = McpData::seed($this->s->tenantB, $this->s->marker);
    $this->promptsB = McpData::prompts($this->s->tenantB);
});

test('the register and the server list the same tools, with the permission each tool checks', function () {
    $register = require __DIR__.'/mcp-tools.php';
    $server = (new ReflectionClass(EkkoServer::class))->getProperty('tools')->getDefaultValue();

    expect(array_keys($register))->toEqualCanonicalizing($server);
    foreach ($register as $class => $entry) {
        expect(app($class)->permission())->toBe($entry['permission'], class_basename($class));
    }
});

test('in the own tenant the role decides, and nothing from tenant B shows', function (string $class) {
    $entry = mcpToolEntry($class);

    foreach (RouteAccess::TENANT_ROLES as $role) {
        $token = McpTestClient::connect($this->s->userWithRole($role), $this->s->tenantA, write: true);
        $result = McpTestClient::call($this, $token, mcpToolName($class), ($entry['arguments'])($this->idsA));
        $tenantRole = $role === 'owner' ? TenantRole::Admin : TenantRole::from($role);
        $allowed = ($entry['permission'] === null || $tenantRole->grants($entry['permission']))
            && (! isset($entry['allowed_roles']) || in_array($role, $entry['allowed_roles'], true));

        expect($result['isError'])->toBe(! $allowed, "{$class} as {$role}: ".$result['text'])
            ->and(stripos($result['raw'], $this->s->marker))->toBeFalse("{$class} as {$role} showed data from tenant B");
        if (! $allowed) {
            expect($result['text'])->toBe(__('mcp.errors.forbidden'));
        }
    }

    expect(McpData::prompts($this->s->tenantB))->toBe($this->promptsB);
})->with(fn () => mcpToolKeys());

test('ids from tenant B used in tenant A reach nothing in B', function (string $class) {
    $entry = mcpToolEntry($class);

    foreach (RouteAccess::TENANT_ROLES as $role) {
        $token = McpTestClient::connect($this->s->userWithRole($role), $this->s->tenantA, write: true);
        $result = McpTestClient::call($this, $token, mcpToolName($class), ($entry['arguments'])($this->idsB));

        expect(stripos($result['raw'], $this->s->marker))->toBeFalse("{$class} as {$role} showed data from tenant B")
            ->and(McpData::prompts($this->s->tenantB))->toBe($this->promptsB, "{$class} as {$role} changed tenant B");
    }
})->with(fn () => mcpToolKeys());

test('naming tenant B as the tenant is refused for every role, also the partner advisor', function (string $class) {
    $entry = mcpToolEntry($class);

    foreach (RouteAccess::TENANT_ROLES as $role) {
        $token = McpTestClient::connect($this->s->userWithRole($role), $this->s->tenantA, write: true);
        $result = McpTestClient::call($this, $token, mcpToolName($class), [...($entry['arguments'])($this->idsB), 'tenant' => $this->s->tenantB->id]);

        expect($result['isError'])->toBeTrue("{$class} as {$role} answered for tenant B")
            ->and($result['text'])->toBe(__('mcp.errors.unknown_tenant'))
            ->and(stripos($result['raw'], $this->s->marker))->toBeFalse()
            ->and(McpData::prompts($this->s->tenantB))->toBe($this->promptsB);
    }
})->with(fn () => mcpToolKeys());

test('the partner advisor reaches the client tenant with the tenant argument, the other roles do not', function (string $class) {
    $entry = mcpToolEntry($class);
    $arguments = [...($entry['arguments'])($this->idsClient), 'tenant' => $this->s->clientOfA->id];

    $advisor = McpTestClient::call($this, McpTestClient::connect($this->s->userWithRole('partner_advisor'), $this->s->tenantA, write: true), mcpToolName($class), $arguments);
    $editor = McpTestClient::call($this, McpTestClient::connect($this->s->userWithRole('editor'), $this->s->tenantA, write: true), mcpToolName($class), $arguments);

    if ($entry['client_allowed'] ?? true) {
        expect($advisor['data']['tenant']['id'])->toBe($this->s->clientOfA->id);
    } else {
        expect($advisor['text'])->toBe(__('mcp.errors.forbidden'));
    }

    expect($advisor['isError'])->toBe(! ($entry['client_allowed'] ?? true), "{$class}: ".$advisor['text'])
        ->and(stripos($advisor['raw'], $this->s->marker))->toBeFalse()
        ->and($editor['text'])->toBe(__('mcp.errors.unknown_tenant'));
})->with(fn () => mcpToolKeys());
