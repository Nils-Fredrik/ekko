<?php

namespace App\Onboarding\Fetch;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        // Names that only the system resolver knows (the hosts file, search domains).
        if ($addresses === []) {
            $v4 = @gethostbynamel($host);
            $addresses = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($addresses));
    }
}
