<?php

namespace App\BotLogs;

use DateTimeImmutable;
use DateTimeZone;

/** @phpstan-import-type HitRow from BotHits */
final class CombinedAccessLog
{
    private const LINE = <<<'REGEX'
        ~\A\S+ \S+ \S+ \[(?<timestamp>[^\]]+)\] "[A-Z]+ (?<target>[^"\s]+)(?: HTTP/[0-9.]+)?" \d{3} (?:\d+|-) "(?:[^"\\]|\\.)*" "(?<agent>(?:[^"\\]|\\.)*)"\h*\z~D
        REGEX;

    /**
     * @param  array<string, string>  $bots  User-agent substring => bot name, in match order.
     * @return array{dates: list<string>, hits: list<HitRow>}
     */
    public function aggregate(string $compressed, string $domain, array $bots): array
    {
        $paths = [];
        $dates = [];

        foreach ($this->lines($compressed) as $line) {
            if (str_contains($line, "\0") || preg_match(self::LINE, $line, $fields) !== 1) {
                continue;
            }
            $time = DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O', $fields['timestamp']);
            if ($time === false || DateTimeImmutable::getLastErrors() !== false) {
                continue;
            }

            $path = $this->path($fields['target'], $domain);
            if ($path === null) {
                continue;
            }
            $day = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
            $dates[$day] = true;

            foreach ($bots as $agent => $bot) {
                if (stripos($fields['agent'], $agent) !== false) {
                    $paths[$day][$bot][$path] = ($paths[$day][$bot][$path] ?? 0) + 1;
                    break;
                }
            }
        }

        if ($dates === []) {
            throw new InvalidAccessLog('The upload contains no applicable combined-format requests.');
        }

        $hits = [];
        foreach ($paths as $day => $botsByDay) {
            foreach ($botsByDay as $bot => $pathCounts) {
                ksort($pathCounts, SORT_STRING);
                arsort($pathCounts, SORT_NUMERIC);
                $top = [];
                foreach (array_slice($pathCounts, 0, 10, preserve_keys: true) as $path => $count) {
                    $top[] = ['path' => $path, 'hits' => $count];
                }

                $hits[] = [
                    'date' => $day,
                    'domain' => $domain,
                    'bot' => $bot,
                    'hits' => array_sum($pathCounts),
                    'unique_paths' => count($pathCounts),
                    'top_paths' => $top,
                ];
            }
        }

        return ['dates' => array_keys($dates), 'hits' => $hits];
    }

    private function path(string $target, string $domain): ?string
    {
        if ($target === '*' || str_starts_with($target, '/')) {
            return explode('#', explode('?', $target, 2)[0], 2)[0];
        }

        $parts = parse_url($target);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || BrandLogDomains::normalize($parts['host'] ?? '') !== $domain) {
            return null;
        }

        return $parts['path'] ?? '/';
    }

    /** @return iterable<string> */
    private function lines(string $compressed): iterable
    {
        $offset = 0;
        $bytes = 0;
        $line = '';
        $total = strlen($compressed);
        $limit = config()->integer('ekko.bot_logs.max_expanded_bytes');

        // gzip may contain consecutive members. A member is complete only after zlib checks its
        // trailer and checksum. Accumulated metrics are written only after the entire file passes.
        while ($offset < $total) {
            $stream = inflate_init(ZLIB_ENCODING_GZIP);
            $memberStart = $offset;
            do {
                $chunk = substr($compressed, $offset, 8192);
                $output = $stream === false ? false : @inflate_add($stream, $chunk, ZLIB_SYNC_FLUSH);
                if ($output === false) {
                    throw new InvalidAccessLog('Invalid gzip member.');
                }
                $offset = $memberStart + inflate_get_read_len($stream);
                $bytes += strlen($output);
                if ($bytes > $limit) {
                    throw new InvalidAccessLog('The expanded log exceeds the allowed size.');
                }

                $parts = explode("\n", $line.$output);
                $line = array_pop($parts);
                foreach ($parts as $part) {
                    if (strlen($part) > 65536) {
                        throw new InvalidAccessLog('An access-log line exceeds the allowed size.');
                    }
                    yield rtrim($part, "\r");
                }
                if (strlen($line) > 65536) {
                    throw new InvalidAccessLog('An access-log line exceeds the allowed size.');
                }
            } while (inflate_get_status($stream) !== ZLIB_STREAM_END && $offset < $total);

            if (inflate_get_status($stream) !== ZLIB_STREAM_END) {
                throw new InvalidAccessLog('Incomplete gzip member.');
            }
        }

        if ($line !== '') {
            yield rtrim($line, "\r");
        }
    }
}
