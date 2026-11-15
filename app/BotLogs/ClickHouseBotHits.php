<?php

namespace App\BotLogs;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/** @phpstan-import-type HitRow from BotHits */
final class ClickHouseBotHits
{
    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, bool>  $domains
     * @param  array<string, string>  $bots
     * @return list<HitRow>
     */
    public function forDay(array $connection, CarbonImmutable $date, array $domains, array $bots): array
    {
        $host = $this->setting($connection, 'host');
        $endpoint = str_starts_with($host, 'https://') ? $host : 'https://'.$host;
        if (preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{1,5})?\z~iD', $endpoint) !== 1
            || ! in_array($endpoint, config()->array('ekko.bot_logs.clickhouse_endpoints'), true)) {
            throw new ClickHouseSyncFailed('The ClickHouse endpoint is not configured.');
        }

        $database = $this->identifier($connection, 'database');
        $table = $this->identifier($connection, 'table');
        $query = <<<SQL
            SELECT site AS domain, bot, sum(path_hits) AS hits, count() AS unique_paths,
                groupArraySorted(10)((-toInt64(path_hits), request_path)) AS top_paths
            FROM
            (
                SELECT site,
                    arrayElement({bots:Array(String)}, arrayFirstIndex(agent -> positionCaseInsensitive(user_agent, agent) > 0, {agents:Array(String)})) AS bot,
                    splitByChar('#', splitByChar('?', path)[1])[1] AS request_path,
                    count() AS path_hits
                FROM `{$database}`.`{$table}`
                WHERE ts >= toDateTime64({start:String}, 3, 'UTC')
                    AND ts < toDateTime64({end:String}, 3, 'UTC')
                    AND (has({domains:Array(String)}, site) OR arrayExists(domain -> endsWith(site, concat('.', domain)), {suffixes:Array(String)}))
                    AND bot != ''
                GROUP BY site, bot, request_path
            )
            GROUP BY site, bot
            FORMAT JSONEachRow
            SQL;

        try {
            $response = Http::withBasicAuth($this->setting($connection, 'user'), $this->setting($connection, 'password'))
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(60)
                ->withQueryParameters([
                    'param_start' => $date->toDateTimeString(),
                    'param_end' => $date->addDay()->toDateTimeString(),
                    'param_domains' => $this->arrayParameter(array_keys($domains)),
                    'param_suffixes' => $this->arrayParameter(array_keys(array_filter($domains))),
                    'param_agents' => $this->arrayParameter(array_keys($bots)),
                    'param_bots' => $this->arrayParameter(array_values($bots)),
                    'wait_end_of_query' => 1,
                ])
                ->withBody($query, 'text/plain')
                ->post($endpoint);
        } catch (ConnectionException) {
            throw new ClickHouseSyncFailed('Could not connect to ClickHouse.');
        }

        if (! $response->successful()) {
            throw new ClickHouseSyncFailed('ClickHouse rejected the request.');
        }

        $hits = [];
        $seen = [];
        foreach (explode("\n", trim($response->body())) as $line) {
            if ($line !== '') {
                $row = $this->parseRow($line, $date->toDateString(), $domains, $bots);
                $key = $row['domain'].'/'.$row['bot'];
                if (isset($seen[$key])) {
                    throw new ClickHouseSyncFailed('ClickHouse returned duplicate aggregates.');
                }
                $seen[$key] = true;
                $hits[] = $row;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, bool>  $domains
     * @param  array<string, string>  $bots
     * @return HitRow
     */
    private function parseRow(string $line, string $date, array $domains, array $bots): array
    {
        try {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ClickHouseSyncFailed('ClickHouse returned invalid JSON.');
        }

        $domain = $row['domain'] ?? null;
        $bot = $row['bot'] ?? null;
        $hits = filter_var($row['hits'] ?? null, FILTER_VALIDATE_INT);
        $unique = filter_var($row['unique_paths'] ?? null, FILTER_VALIDATE_INT);
        $paths = $row['top_paths'] ?? null;
        if (! is_string($domain) || BrandLogDomains::normalize($domain) !== $domain || ! $this->allowsDomain($domain, $domains)
            || ! is_string($bot) || ! in_array($bot, $bots, true)
            || $hits === false || $unique === false || $unique < 1 || $hits < $unique
            || ! is_array($paths) || count($paths) !== min(10, $unique)) {
            throw new ClickHouseSyncFailed('ClickHouse returned an invalid aggregate.');
        }

        $top = [];
        foreach ($paths as $pair) {
            $pathHits = filter_var($pair[0] ?? null, FILTER_VALIDATE_INT);
            $path = $pair[1] ?? null;
            if ($pathHits === false || $pathHits > -1 || $pathHits < -$hits || ! is_string($path)
                || strpbrk($path, '?#') !== false || str_contains($path, "\0")) {
                throw new ClickHouseSyncFailed('ClickHouse returned an invalid path aggregate.');
            }
            $top[] = ['path' => $path, 'hits' => -$pathHits];
        }

        return ['date' => $date, 'domain' => $domain, 'bot' => $bot, 'hits' => $hits, 'unique_paths' => $unique, 'top_paths' => $top];
    }

    /** @param array<string, bool> $domains */
    private function allowsDomain(string $domain, array $domains): bool
    {
        if (array_key_exists($domain, $domains)) {
            return true;
        }
        foreach ($domains as $registered => $includeSubdomains) {
            if ($includeSubdomains && str_ends_with($domain, '.'.$registered)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $values */
    private function arrayParameter(array $values): string
    {
        return '['.implode(',', array_map(fn (string $value): string => "'".addcslashes($value, "\\'")."'", $values)).']';
    }

    /** @param array<string, mixed> $connection */
    private function identifier(array $connection, string $key): string
    {
        $identifier = $this->setting($connection, $key);
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $identifier) !== 1) {
            throw new ClickHouseSyncFailed('Invalid ClickHouse table or database name.');
        }

        return $identifier;
    }

    /** @param array<string, mixed> $connection */
    private function setting(array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new ClickHouseSyncFailed('Incomplete ClickHouse configuration.');
        }

        return $value;
    }
}
