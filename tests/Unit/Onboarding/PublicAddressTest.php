<?php

use App\Onboarding\Fetch\PublicAddress;

// docs/spec.md 16: no private IP addresses. Every special-purpose range, in both IP versions and in the IPv6
// forms that carry an IPv4 address.

it('refuses private, loopback, link-local, metadata and other special IPv4 addresses', function (string $ip) {
    expect(PublicAddress::isPublic($ip))->toBeFalse();
})->with([
    'this network' => '0.0.0.0',
    'this network, other' => '0.1.2.3',
    'private 10' => '10.0.0.1',
    'private 10 end' => '10.255.255.255',
    'carrier-grade NAT' => '100.64.0.1',
    'carrier-grade NAT end' => '100.127.255.255',
    'loopback' => '127.0.0.1',
    'loopback other' => '127.255.255.254',
    'link-local' => '169.254.1.1',
    'cloud metadata' => '169.254.169.254',
    'private 172.16' => '172.16.0.1',
    'private 172.31' => '172.31.255.255',
    'IETF protocol assignments' => '192.0.0.8',
    'documentation 1' => '192.0.2.1',
    'private 192.168' => '192.168.1.1',
    'benchmarking' => '198.18.0.1',
    'benchmarking end' => '198.19.255.255',
    'documentation 2' => '198.51.100.1',
    'documentation 3' => '203.0.113.1',
    'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
]);

it('accepts public IPv4 addresses next to the special ranges', function (string $ip) {
    expect(PublicAddress::isPublic($ip))->toBeTrue();
})->with([
    '8.8.8.8',
    '93.184.216.34',
    'just below 172.16' => '172.15.255.255',
    'just above 172.31' => '172.32.0.1',
    'just below 100.64' => '100.63.255.255',
    'just above 100.127' => '100.128.0.1',
    'just below 198.18' => '198.17.255.255',
    'just above 198.19' => '198.20.0.1',
    'just below 169.254' => '169.253.255.255',
    '11.0.0.1',
]);

it('refuses private and special IPv6 addresses, and the forms that carry an IPv4 address', function (string $ip) {
    expect(PublicAddress::isPublic($ip))->toBeFalse();
})->with([
    'unspecified' => '::',
    'loopback' => '::1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped private' => '::ffff:10.0.0.1',
    'IPv4-mapped metadata' => '::ffff:169.254.169.254',
    'IPv4-mapped hex' => '::ffff:a00:1',
    'IPv4-compatible' => '::7f00:1',
    'NAT64 of metadata' => '64:ff9b::a9fe:a9fe',
    'NAT64 of loopback' => '64:ff9b::7f00:1',
    'link-local' => 'fe80::1',
    'unique local' => 'fc00::1',
    'unique local fd' => 'fd12:3456::1',
    'AWS metadata over IPv6' => 'fd00:ec2::254',
    'site-local' => 'fec0::1',
    'multicast' => 'ff02::1',
    'documentation' => '2001:db8::1',
    'Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    '6to4 of a private address' => '2002:c0a8:0101::1',
    '6to4 of loopback' => '2002:7f00:1::1',
    'discard prefix' => '100::1',
]);

it('accepts global unicast IPv6 addresses', function (string $ip) {
    expect(PublicAddress::isPublic($ip))->toBeTrue();
})->with([
    'google dns' => '2001:4860:4860::8888',
    'cloudflare' => '2606:4700:4700::1111',
    '2a00' => '2a00:1450:4001:81b::200e',
]);

it('refuses what is not an address at all', function (string $value) {
    expect(PublicAddress::isPublic($value))->toBeFalse();
})->with(['', 'example.com', '1.2.3', '1.2.3.4.5', '256.1.1.1', '0x7f.1', '2130706433', '[::1]', '127.0.0.1/8']);

it('recognises IP literals in any bracket form', function (string $host, bool $literal) {
    expect(PublicAddress::isIpLiteral($host))->toBe($literal);
})->with([
    ['127.0.0.1', true],
    ['[::1]', true],
    ['::1', true],
    ['example.com', false],
    ['1.2.3.4.example.com', false],
]);
