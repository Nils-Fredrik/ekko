<?php

namespace App\Onboarding\Fetch;

/** Looks up the IP addresses of a host name. Behind an interface so tests never touch DNS. */
interface HostResolver
{
    /** @return list<string> every IPv4 and IPv6 address of the host, empty when it does not resolve */
    public function resolve(string $host): array;
}
