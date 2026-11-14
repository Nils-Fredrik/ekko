<?php

namespace Tests\Feature\TenantIsolation\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Reads tests/Feature/TenantIsolation/routes.php and maps its keys to registered routes.
 */
final class RouteRegister
{
    /** @var array<string, RouteAccess>|null */
    private static ?array $entries = null;

    /**
     * @return array<string, RouteAccess>
     */
    public static function entries(): array
    {
        return self::$entries ??= require dirname(__DIR__).'/routes.php';
    }

    /**
     * The key a route is registered under: its name, or "METHOD uri" when it has none.
     */
    public static function keyFor(Route $route): string
    {
        $name = $route->getName();

        if ($name !== null) {
            return $name;
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        // All methods, so two unnamed routes on the same URI never share a key.
        return implode('|', $methods ?: ['ANY']).' '.$route->uri();
    }

    public static function route(string $key): Route
    {
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (self::keyFor($route) === $key) {
                return $route;
            }
        }

        throw new RuntimeException("No registered route for '{$key}'.");
    }

    /**
     * @return list<string>
     */
    public static function methods(Route $route): array
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        // A route that answers every method (Route::redirect) is checked with GET.
        return count($methods) > 4 ? ['GET'] : $methods;
    }

    /**
     * The URL for a request. A parameter the route does not have, or a missing parameter on a route
     * that needs a login, is an error: a misspelt parameter must not turn into a 404 that passes
     * the foreign-resource check without testing anything. Signed routes get a valid signature.
     *
     * @param  array<string, mixed>  $params
     */
    public static function url(Route $route, array $params, ?RouteAccess $access = null): string
    {
        $names = $route->parameterNames();

        foreach (array_keys($params) as $name) {
            if (! in_array($name, $names, true)) {
                throw new RuntimeException("Route '".self::keyFor($route)."' has no parameter '{$name}'.");
            }
        }

        if ($access?->requiresLogin()) {
            foreach ($names as $name) {
                if (! array_key_exists($name, $params) && ! str_contains($route->uri(), '{'.$name.'?}')) {
                    throw new RuntimeException("Route '".self::keyFor($route)."' needs parameter '{$name}'.");
                }
            }
        }

        // The full URL: the signature covers the host, so a path alone would always fail validation
        // and the request would never reach the authorization behind it.
        if ($access?->signed && $route->getName() !== null) {
            return URL::signedRoute($route->getName(), $params);
        }

        $uri = $route->uri();

        foreach ($params as $name => $value) {
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $value, $uri);
        }

        // Guest routes (e.g. a password reset token) get a placeholder for what the entry does not give.
        $uri = (string) preg_replace('/\{[^}]+\}/', 'placeholder', $uri);

        return '/'.ltrim($uri, '/');
    }
}
