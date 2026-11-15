<?php

namespace App\Integrations\Ga4;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @phpstan-type Referral array{date: string, source: string, sessions: int, engaged_sessions: int, conversions: string}
 */
final class Ga4Client
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const DIMENSIONS = ['date', 'sessionSource'];

    private const METRICS = ['sessions', 'engagedSessions', 'keyEvents'];

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $sources
     * @return array{rows: array<string, Referral>, complete: bool}
     */
    public function report(array $config, array $sources, string $startDate, string $endDate): array
    {
        $propertyId = $config['property_id'] ?? null;
        if ((! is_string($propertyId) && ! is_int($propertyId))
            || preg_match('/^[1-9][0-9]*$/D', (string) $propertyId) !== 1 || $sources === []) {
            throw new Ga4SyncFailed('invalid_config');
        }

        $token = $this->accessToken($config['service_account'] ?? null);

        try {
            $response = Http::withToken($token)->acceptJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(30)
                ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
                    'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                    'dimensions' => array_map(fn (string $name) => ['name' => $name], self::DIMENSIONS),
                    'metrics' => array_map(fn (string $name) => ['name' => $name], self::METRICS),
                    'dimensionFilter' => [
                        'filter' => [
                            'fieldName' => 'sessionSource',
                            'inListFilter' => ['values' => $sources, 'caseSensitive' => true],
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new Ga4SyncFailed('connection_failed');
        }

        if (! $response->successful()) {
            throw new Ga4SyncFailed(match ($response->status()) {
                401, 403 => 'access_denied',
                default => 'http_'.$response->status(),
            });
        }

        return $this->parseReport($response->json(), $sources, $startDate, $endDate);
    }

    private function accessToken(mixed $account): string
    {
        $email = data_get($account, 'client_email');
        $privateKey = data_get($account, 'private_key');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_string($privateKey) || $privateKey === '') {
            throw new Ga4SyncFailed('invalid_config');
        }

        try {
            // Only accept the signing credentials. The Google library supplies the token endpoint.
            $credentials = new ServiceAccountCredentials(self::SCOPE, [
                'client_email' => $email,
                'private_key' => $privateKey,
            ]);
            $token = $credentials->fetchAuthToken(fn (RequestInterface $request): ResponseInterface => Http::withoutRedirecting()
                ->connectTimeout(5)->timeout(15)
                ->send($request->getMethod(), (string) $request->getUri(), [
                    'headers' => $request->getHeaders(),
                    'body' => $request->getBody(),
                ])->toPsrResponse())['access_token'] ?? null;
        } catch (ConnectionException) {
            throw new Ga4SyncFailed('connection_failed');
        } catch (Throwable) {
            // Do not chain provider exceptions, whose messages may include request or response contents.
            throw new Ga4SyncFailed('authentication_failed');
        }

        if (! is_string($token) || $token === '') {
            throw new Ga4SyncFailed('authentication_failed');
        }

        return $token;
    }

    /**
     * @param  list<string>  $sources
     * @return array{rows: array<string, Referral>, complete: bool}
     */
    private function parseReport(mixed $report, array $sources, string $startDate, string $endDate): array
    {
        $dimensions = data_get($report, 'dimensionHeaders.*.name');
        $metrics = data_get($report, 'metricHeaders.*.name');
        $rawRows = data_get($report, 'rows', []);

        if ($dimensions !== self::DIMENSIONS || $metrics !== self::METRICS || ! is_array($rawRows)
            || data_get($report, 'rowCount', 0) !== count($rawRows)) {
            throw new Ga4SyncFailed('invalid_report');
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            $source = data_get($rawRow, 'dimensionValues.1.value');
            if (! is_string($source)) {
                throw new Ga4SyncFailed('invalid_report');
            }

            if (! in_array($source, $sources, true)) {
                continue;
            }

            $date = $this->reportDate(data_get($rawRow, 'dimensionValues.0.value'));
            $sessions = $this->sessionCount(data_get($rawRow, 'metricValues.0.value'));
            $engaged = $this->sessionCount(data_get($rawRow, 'metricValues.1.value'));
            $keyEvents = data_get($rawRow, 'metricValues.2.value');
            $key = $date.'|'.$source;

            if ($date < $startDate || $date > $endDate || isset($rows[$key])
                || ! is_string($keyEvents) || preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,16})?(?:[Ee][+-]?[0-9]{1,3})?$/D', $keyEvents) !== 1) {
                throw new Ga4SyncFailed('invalid_report');
            }
            $conversions = BigDecimal::of($keyEvents)->toScale(4, RoundingMode::HalfUp);
            if ($conversions->isGreaterThan('999999999999.9999')) {
                throw new Ga4SyncFailed('invalid_report');
            }

            $rows[$key] = [
                'date' => $date,
                'source' => $source,
                'sessions' => $sessions,
                'engaged_sessions' => $engaged,
                'conversions' => (string) $conversions,
            ];
        }

        return [
            'rows' => $rows,
            'complete' => ! data_get($report, 'metadata.dataLossFromOtherRow', false)
                && ! data_get($report, 'metadata.subjectToThresholding', false)
                && ! data_get($report, 'metadata.samplingMetadatas', []),
        ];
    }

    private function reportDate(mixed $date): string
    {
        if (! is_string($date) || preg_match('/^[0-9]{8}$/D', $date) !== 1
            || ! checkdate((int) substr($date, 4, 2), (int) substr($date, 6, 2), (int) substr($date, 0, 4))) {
            throw new Ga4SyncFailed('invalid_report');
        }

        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    private function sessionCount(mixed $value): int
    {
        $count = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($count === false) {
            throw new Ga4SyncFailed('invalid_report');
        }

        return $count;
    }
}
