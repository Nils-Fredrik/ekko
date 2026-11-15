<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Which proxies may tell the app the client's address (X-Forwarded-For) and scheme (X-Forwarded-Proto).
    | Read by Laravel's TrustProxies middleware on every request. The headers themselves are fixed in
    | bootstrap/app.php: Host, Port and Prefix are never taken from a proxy.
    |
    | Empty (local, tests): no proxy is trusted, and $request->ip() is the connecting address.
    | "*" (Cloud Run, set in infra/terraform): the connecting address is Google's front end and is trusted,
    | so $request->ip() is the last address in X-Forwarded-For, the one the front end appended. Addresses a
    | client puts in the header itself come before it and are never used. Without this every client behind
    | Cloud Run would share one address and one rate-limit bucket (T39-F3).
    |
    | Only set "*" where every request passes exactly one proxy that appends the client's address. A load
    | balancer in front of Cloud Run adds a hop: then every hop that appends an address must be listed, both
    | the connecting address (Cloud Run's internal front, often 169.254.0.0/16) and the load balancer's
    | addresses. Listing only the load balancer leaves the connecting address untrusted (docs/runbook.md).
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
