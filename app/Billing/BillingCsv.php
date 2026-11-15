<?php

namespace App\Billing;

use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BillingCsv
{
    /** @param list<array<string, mixed>> $rows */
    public function render(array $rows): string
    {
        if (array_any($rows, fn ($row) => $row['invoice_total_usd'] === null)) {
            throw ValidationException::withMessages(['month' => __('billing.missing_markup')]);
        }
        $engines = [];
        foreach ($rows as $row) {
            $engines = array_merge($engines, array_keys($row['engine_costs_usd']));
        }
        $engines = array_values(array_unique($engines));
        sort($engines);
        $columns = ['month', 'account_id', 'account_name', 'tenant_id', 'tenant_name', 'plan', 'calls', 'platform_cost_usd', 'byok_cost_usd', 'markup_percent', 'markup_usd', 'invoice_total_usd'];
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('billing_export_unavailable');
        }
        try {
            fputcsv($stream, [...array_map(fn ($key) => __('billing.csv.'.$key), $columns), ...array_map(fn ($engine) => __('billing.csv.engine_cost', ['engine' => $engine]), $engines)], ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                $values = array_map(fn ($key) => self::cell((string) $row[$key]), $columns);
                foreach ($engines as $engine) {
                    $values[] = $row['engine_costs_usd'][$engine] ?? '0.00000000';
                }
                fputcsv($stream, $values, ',', '"', '', "\r\n");
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('billing_export_unavailable');
            }

            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private static function cell(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
