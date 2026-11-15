<?php

use App\Http\Controllers\Mcp\ConnectionController;
use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\EkkoServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| The Claude connection (MCP, docs/spec.md 15.2, ADR 0016). The server is on /mcp and only takes access tokens
| from a Claude connection (AuthenticateMcp). Ekko is its own authorization server (OAuthController): metadata,
| client registration, consent with the ordinary login, tokens and revocation.
*/
Mcp::web('/mcp', EkkoServer::class)
    ->middleware([AuthenticateMcp::class, 'throttle:mcp'])
    ->name('mcp');

// Metadata. laravel/mcp uses the name of the nested route in WWW-Authenticate.
Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/{path}', [OAuthController::class, 'protectedResource'])
    ->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');
Route::get('/.well-known/oauth-authorization-server/{path}', [OAuthController::class, 'authorizationServer'])
    ->where('path', '.*')->name('mcp.oauth.authorization-server.nested');

// Without session and CSRF: Claude calls these directly.
Route::middleware('throttle:mcp-oauth')->group(function () {
    Route::post('/oauth/register', [OAuthController::class, 'register'])->name('mcp.oauth.register');
    Route::post('/oauth/token', [OAuthController::class, 'token'])->name('mcp.oauth.token');
    Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])->name('mcp.oauth.revoke');
});

// Consent and the connection list: the ordinary login, as for the other pages.
Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->name('mcp.oauth.authorize');
    Route::post('/oauth/authorize', [OAuthController::class, 'decide'])->name('mcp.oauth.decide');
    Route::get('/claude', [ConnectionController::class, 'index'])->name('mcp.connections');
    Route::delete('/claude/{connection}', [ConnectionController::class, 'destroy'])->whereNumber('connection')->name('mcp.connections.destroy');
});
