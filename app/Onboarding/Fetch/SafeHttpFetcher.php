<?php

namespace App\Onboarding\Fetch;

/**
 * Fetches one address for the wizard with SSRF protection (docs/spec.md 16, ADR 0018).
 *
 * Every request, including each redirect hop, goes through the same steps: the address must be https on
 * the default port, the host is resolved here, every address it resolves to must be public (one private
 * address refuses the host, so a mixed answer cannot be used to pick), and the request is pinned to the
 * address that was checked ({@see PageTransport}). Redirects are followed by hand, at most
 * ekko.wizard.fetch.max_redirects of them. The body is limited in size, the request in time, and only
 * HTML, XML and plain text are accepted.
 */
final class SafeHttpFetcher
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly PageTransport $transport,
    ) {}

    /**
     * @param  list<string>  $acceptedTypes  media types the body may have, such as text/html
     * @param  float|null  $deadline  microtime(true) value after which no request is started
     *
     * @throws FetchFailed
     */
    public function get(string $url, array $acceptedTypes, ?float $deadline = null): FetchedPage
    {
        /** @var array{max_redirects: int, max_bytes: int, request_timeout_seconds: int} $limits */
        $limits = config('ekko.wizard.fetch');

        for ($hop = 0; $hop <= $limits['max_redirects']; $hop++) {
            ['url' => $url, 'host' => $host] = SiteUrl::validate($url);
            $ip = $this->pinnedAddress($host);

            $timeout = $limits['request_timeout_seconds'];
            if ($deadline !== null) {
                $left = (int) floor($deadline - microtime(true));
                if ($left < 1) {
                    throw FetchFailed::because(FetchFailed::TIMEOUT);
                }
                $timeout = min($timeout, $left);
            }

            $response = $this->transport->get($url, $host, $ip, $limits['max_bytes'], $timeout);

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $next = $response->location === null ? null : UrlResolver::resolve($url, $response->location);
                if ($next === null) {
                    throw FetchFailed::because(FetchFailed::HTTP_ERROR);
                }
                $url = $next;

                continue;
            }

            if ($response->status < 200 || $response->status >= 300) {
                throw FetchFailed::because(FetchFailed::HTTP_ERROR);
            }
            if ($response->truncated || strlen($response->body) > $limits['max_bytes'] || ($response->contentLength ?? 0) > $limits['max_bytes']) {
                throw FetchFailed::because(FetchFailed::TOO_LARGE);
            }

            $type = strtolower(trim(explode(';', (string) $response->contentType)[0]));
            if (! in_array($type, $acceptedTypes, true)) {
                throw FetchFailed::because(FetchFailed::UNSUPPORTED_CONTENT);
            }

            return new FetchedPage($url, $type, $response->body);
        }

        throw FetchFailed::because(FetchFailed::TOO_MANY_REDIRECTS);
    }

    /** @throws FetchFailed */
    private function pinnedAddress(string $host): string
    {
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw FetchFailed::because(FetchFailed::DNS);
        }

        foreach ($addresses as $address) {
            if (! PublicAddress::isPublic($address)) {
                throw FetchFailed::because(FetchFailed::BLOCKED_ADDRESS);
            }
        }

        // IPv4 first: the widest support. The address is passed on to the transport, which must connect to it.
        usort($addresses, fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses[0];
    }
}
