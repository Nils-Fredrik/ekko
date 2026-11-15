<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpTokens;
use App\Models\McpConnection;
use App\Models\User;
use App\Tenancy\ActingAs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Claude": how to connect Claude to Ekko, and the user's own connections, with disconnecting (as in Varde).
 * Only the user's own connections are listed and can be disconnected.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $connections = McpConnection::query()->with('team:id,name')->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('revoked_at')->orWhere('revoked_at', '>=', now()->subDays(90)))
            ->orderByRaw('revoked_at is not null')->latest('id')->get();

        return Inertia::render('Mcp/Connections', [
            'serverUrl' => url('/mcp'),
            'connections' => $connections->map(fn (McpConnection $connection): array => [
                'id' => $connection->id,
                'client' => $connection->client_name,
                'host' => $connection->redirect_host,
                'tenant' => $connection->team?->name,
                'write' => $connection->canWrite(),
                'created_at' => $connection->created_at?->toIso8601String(),
                'last_used_at' => $connection->last_used_at?->toIso8601String(),
                'revoked_at' => $connection->revoked_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function destroy(Request $request, int $connection, McpTokens $tokens, ActingAs $actingAs): RedirectResponse
    {
        abort_if($actingAs->state() !== null, 403);
        $row = McpConnection::query()->whereKey($connection)->where('user_id', $request->user()?->getAuthIdentifier())->first();
        abort_if($row === null, 404);
        $tokens->revoke($row);

        return back()->banner(__('mcp.connections.revoked'));
    }
}
