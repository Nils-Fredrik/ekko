<?php

namespace Tests\Feature\TenantIsolation\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
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

        return ($methods[0] ?? 'ANY').' '.$route->uri();
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
     * @param  array<string, mixed>  $params
     */
    public static function url(Route $route, array $params): string
    {
        $uri = $route->uri();

        foreach ($params as $name => $value) {
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $value, $uri);
        }

        // Parameters the entry does not give (e.g. a reset token) get a placeholder.
        $uri = (string) preg_replace('/\{[^}]+\}/', 'placeholder', $uri);

        return '/'.ltrim($uri, '/');
    }
}
