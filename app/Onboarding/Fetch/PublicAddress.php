<?php

namespace App\Onboarding\Fetch;

/**
 * Decides whether an IP address is a public internet address (docs/spec.md 16, SSRF protection).
 *
 * IPv4 uses a deny list of the special-purpose blocks (RFC 6890, including the ranges PHP's own filter
 * misses, such as carrier-grade NAT). IPv6 uses an allow list: only global unicast 2000::/3 is public,
 * minus the blocks inside it that are documentation or tunnel other addresses (Teredo, 6to4). That excludes
 * loopback, link-local, unique local (including the cloud metadata address fd00:ec2::254), multicast,
 * IPv4-mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96) in one rule, so none of them can reach a private
 * IPv4 address through an IPv6 address.
 */
final class PublicAddress
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '2001::/32',
        '2001:2::/48',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inRange($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return false;
            }
        }

        return self::inRange($packed, '2000::/3');
    }

    public static function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);
        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (substr($packed, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
