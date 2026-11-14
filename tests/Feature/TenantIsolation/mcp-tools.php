<?php

/*
|--------------------------------------------------------------------------
| MCP tool register (docs/spec.md 15.2, T34)
|--------------------------------------------------------------------------
|
| Every class in app/Mcp/Tools, with the permission it needs in the current tenant
| (App\Enums\TenantPermission). A tool without an entry fails CodebaseGuardsTest. T34 adds the
| tools and extends RouteIsolationTest with calls against another tenant.
|
*/

return [
];
