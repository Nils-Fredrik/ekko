<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * CSV import and export of prompts (docs/spec.md 14.2). Columns are text, market (country),
 * language, location, topic, intent, funnel_stage, priority, active and market_id.
 * Country alone is not unique when one country has several languages (docs/spec.md 6.2).
 */
final class PromptCsv
{
    /** @var list<string> */
    public const COLUMNS = ['text', 'market', 'language', 'location', 'topic', 'intent', 'funnel_stage', 'priority', 'active', 'market_id'];

    /**
     * A cell that starts with =, +, -, @, a tab or a carriage return is a formula in a spreadsheet.
     */
    private const FORMULA_PREFIX = '/^[=+\\-@\\t\\r]/';

    public static function export(Brand $brand): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        self::writeRow($handle, self::COLUMNS);

        $prompts = Prompt::query()
            ->whereIn('market_id', $brand->markets->pluck('id'))
            ->with(['market', 'topic'])
            ->orderBy('id')
            ->get();

        foreach ($prompts as $prompt) {
            if ($prompt->market === null) {
                continue;
            }

            self::writeRow($handle, [
                self::spreadsheetCell($prompt->text),
                $prompt->market->country,
                $prompt->market->language,
                self::spreadsheetCell($prompt->market->location_label ?? ''),
                self::spreadsheetCell($prompt->topic_id === null ? '' : $prompt->topic->name),
                $prompt->intent,
                $prompt->funnel_stage,
                $prompt->priority,
                $prompt->is_active ? '1' : '0',
                $prompt->market->id,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return array{created: int, skipped: int, errors: list<string>, warnings: list<string>}
     */
    public static function import(Brand $brand, string $csv): array
    {
        if (! mb_check_encoding($csv, 'UTF-8')) {
            return ['created' => 0, 'skipped' => 0, 'errors' => [__('app.library.bad_encoding')], 'warnings' => []];
        }

        return DB::transaction(function () use ($brand, $csv): array {
            Market::query()->where('brand_id', $brand->id)->orderBy('id')->lockForUpdate()->get(['id']);

            return self::read($brand, $csv);
        });
    }

    /**
     * @return array{created: int, skipped: int, errors: list<string>, warnings: list<string>}
     */
    private static function read(Brand $brand, string $csv): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $result['errors'][] = __('app.library.bad_header');

            return $result;
        }

        fwrite($handle, ltrim($csv, "\xEF\xBB\xBF"));
        rewind($handle);
        $header = self::readRow($handle);
        $columns = is_array($header) ? array_map(fn ($column) => strtolower(trim((string) $column)), $header) : [];

        if (! in_array('text', $columns, true) || ! in_array('market', $columns, true)) {
            fclose($handle);
            $result['errors'][] = __('app.library.bad_header');

            return $result;
        }

        $rowNumber = 1;
        while (($row = self::readRow($handle)) !== false) {
            $rowNumber++;
            if ($row === [null]) {
                continue;
            }
            if ($rowNumber > 1001) {
                $result['errors'][] = __('app.library.row_error', ['row' => $rowNumber, 'message' => __('app.library.too_many_rows')]);
                break;
            }

            $values = [];
            $hasNull = false;
            foreach ($columns as $index => $column) {
                $cell = (string) ($row[$index] ?? '');
                $hasNull = $hasNull || str_contains($cell, "\0");
                $values[$column] = self::fromSpreadsheet(trim($cell));
            }
            if ($hasNull) {
                $result['skipped']++;
                $result['errors'][] = __('app.library.row_error', ['row' => $rowNumber, 'message' => __('app.library.invalid_row')]);

                continue;
            }

            $error = self::storeRow($brand, $values);
            if ($error === null) {
                $result['created']++;
                if (PersonalData::in($values['text'] ?? '')) {
                    $result['warnings'][] = __('app.library.personal_data_row', ['row' => $rowNumber]);
                }
            } else {
                $result['skipped']++;
                $result['errors'][] = __('app.library.row_error', ['row' => $rowNumber, 'message' => $error]);
            }
        }

        fclose($handle);

        return $result;
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function storeRow(Brand $brand, array $values): ?string
    {
        $text = $values['text'] ?? '';
        if ($text === '' || str_contains($text, "\0") || mb_strlen($text) > Prompt::TEXT_MAX) {
            return __('app.library.invalid_row');
        }

        $market = self::market($brand, $values);
        if (! $market instanceof Market) {
            return $market;
        }

        $topicId = null;
        $topicName = $values['topic'] ?? '';
        if ($topicName !== '') {
            $topic = Topic::query()->where('brand_id', $brand->id)->where('name', $topicName)->first();
            if ($topic === null) {
                return __('app.library.unknown_topic');
            }
            $topicId = $topic->id;
        }

        $intent = self::choice($values['intent'] ?? '', Prompt::INTENTS, 'category');
        $funnel = self::choice($values['funnel_stage'] ?? '', Prompt::FUNNELS, 'awareness');
        $priority = self::choice($values['priority'] ?? '', Prompt::PRIORITIES, 'standard');
        if ($intent === null || $funnel === null || $priority === null) {
            return __('app.library.invalid_row');
        }

        $active = ! in_array(strtolower($values['active'] ?? '1'), ['0', 'false', 'no'], true);
        $hash = Prompt::hashText($text);
        if (Prompt::query()->where('market_id', $market->id)->where('text_hash', $hash)->exists()) {
            return __('app.library.duplicate');
        }

        if ($active && ! self::withinQuota($market->id)) {
            return __('app.library.quota');
        }

        Prompt::query()->create([
            'market_id' => $market->id,
            'topic_id' => $topicId,
            'text' => $text,
            'intent' => $intent,
            'funnel_stage' => $funnel,
            'priority' => $priority,
            'is_active' => $active,
            'text_hash' => $hash,
        ]);

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function market(Brand $brand, array $values): Market|string
    {
        $country = strtoupper($values['market'] ?? '');
        $location = $values['location'] ?? '';
        $language = strtolower($values['language'] ?? '');
        $id = $values['market_id'] ?? '';

        if ($id !== '') {
            if (preg_match('/^\d+$/', $id) !== 1) {
                return __('app.library.unknown_market');
            }

            $market = $brand->markets->first(fn (Market $market): bool => (int) $market->id === (int) $id);
            if (! $market instanceof Market) {
                return __('app.library.unknown_market');
            }
            if ($country !== '' && strtoupper((string) $market->country) !== $country) {
                return __('app.library.ambiguous_market');
            }
            if ($location !== '' && (string) $market->location_label !== $location) {
                return __('app.library.ambiguous_market');
            }
            if ($language !== '' && strtolower((string) $market->language) !== $language) {
                return __('app.library.ambiguous_market');
            }

            return $market;
        }

        $matches = $brand->markets->filter(function (Market $market) use ($country, $location, $language): bool {
            if (strtoupper((string) $market->country) !== $country) {
                return false;
            }
            if ($location !== '' && (string) $market->location_label !== $location) {
                return false;
            }
            if ($language !== '' && strtolower((string) $market->language) !== $language) {
                return false;
            }

            return true;
        });

        if ($matches->isEmpty()) {
            return __('app.library.unknown_market');
        }

        if ($matches->count() > 1) {
            return __('app.library.ambiguous_market');
        }

        return $matches->first();
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function choice(string $value, array $allowed, string $default): ?string
    {
        if ($value === '') {
            return $default;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * An empty escape keeps a trailing backslash in the cell. The default escape would swallow the
     * closing quote and join the next prompt into this one on import.
     *
     * @param  resource  $handle
     * @param  list<int|string|null>  $fields
     */
    private static function writeRow($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '');
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|false
     */
    private static function readRow($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }

    /**
     * A leading apostrophe makes a formula cell into text. A prompt that already starts with that
     * marker is escaped again, so import can put the original text back.
     */
    private static function spreadsheetCell(string $value): string
    {
        if (self::needsSpreadsheetMarker($value)) {
            return "'".$value;
        }

        return $value;
    }

    private static function fromSpreadsheet(string $value): string
    {
        if (str_starts_with($value, "'") && self::needsSpreadsheetMarker(substr($value, 1))) {
            return substr($value, 1);
        }

        return $value;
    }

    /**
     * True when the cell is a formula, or a literal apostrophe in front of one. Import strips one
     * marker, so each of those layers has to be escaped on the way out.
     */
    private static function needsSpreadsheetMarker(string $value): bool
    {
        $rest = $value;
        while (str_starts_with($rest, "'")) {
            $rest = substr($rest, 1);
        }

        return $rest !== '' && preg_match(self::FORMULA_PREFIX, $rest) === 1;
    }

    private static function withinQuota(int $marketId): bool
    {
        $tenantId = app(TenantContext::class)->id();
        $plan = $tenantId === null ? null : Team::query()->find($tenantId)?->plan;
        $max = $plan instanceof Plan ? $plan->max_prompts_per_market : null;

        if ($max === null) {
            return true;
        }

        return Prompt::query()->where('market_id', $marketId)->where('is_active', true)->count() < $max;
    }
}
