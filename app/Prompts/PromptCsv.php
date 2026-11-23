<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
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

    public static function export(Brand $brand, bool $includeArchived = false): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        self::writeRow($handle, self::COLUMNS);

        $prompts = Prompt::query()
            ->whereIn('market_id', $brand->markets->pluck('id'))
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
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

    /** @return array<string, mixed> */
    public static function import(Brand $brand, string $csv): array
    {
        return DB::transaction(function () use ($brand, $csv): array {
            $markets = $brand->markets()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $brand->setRelation('markets', $markets);
            $diff = self::preview($brand, $csv);
            $library = app(PromptLibrary::class);
            foreach ($diff['changed'] as $row) {
                $prompt = Prompt::query()->findOrFail($row['id']);
                $library->saveLocked($markets->get($prompt->market_id), $prompt, array_map(fn ($pair) => $pair[1], $row['fields']));
            }
            foreach ($diff['created'] as $row) {
                $library->saveLocked($markets->get($row['market_id']), null, $row);
            }

            return [
                'created' => count($diff['created']), 'changed' => count($diff['changed']),
                'skipped' => $diff['unchanged'] + count($diff['errors']),
                'errors' => array_map(fn ($error) => $error['line'] === null ? $error['message'] : __('app.library.row_error', ['row' => $error['line'], 'message' => $error['message']]), $diff['errors']),
                'warnings' => $diff['warnings'],
            ];
        });
    }

    /** @return array{created: list<array<string, mixed>>, changed: list<array<string, mixed>>, unchanged: int, errors: list<array{line: int|null, message: string}>, warnings: list<string>} */
    public static function preview(Brand $brand, string $csv): array
    {
        $result = ['created' => [], 'changed' => [], 'unchanged' => 0, 'errors' => [], 'warnings' => []];
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $result['errors'][] = ['line' => null, 'message' => __('app.library.bad_encoding')];

            return $result;
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV stream');
        }
        try {
            fwrite($handle, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
            rewind($handle);
            $header = self::readRow($handle);
            $columns = is_array($header) ? array_map(fn ($column) => strtolower(trim((string) $column)), $header) : [];
            if (! in_array('text', $columns, true) || ! in_array('market', $columns, true)) {
                $result['errors'][] = ['line' => null, 'message' => __('app.library.bad_header')];

                return $result;
            }
            $limit = Team::query()->findOrFail(current_tenant_id())->plan?->max_prompts_per_market;
            $counts = Prompt::query()->whereIn('market_id', $brand->markets->pluck('id'))->where('is_active', true)
                ->groupBy('market_id')->selectRaw('market_id, count(*) as total')->pluck('total', 'market_id')->all();
            $seen = [];
            $line = 1;
            while (($row = self::readRow($handle)) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                if ($line > 1001) {
                    $result['errors'][] = ['line' => $line, 'message' => __('app.library.too_many_rows')];
                    break;
                }
                $values = [];
                foreach ($columns as $index => $column) {
                    $cell = (string) ($row[$index] ?? '');
                    if (str_contains($cell, "\0")) {
                        $result['errors'][] = ['line' => $line, 'message' => __('app.library.invalid_row')];

                        continue 2;
                    }
                    $values[$column] = self::fromSpreadsheet(trim($cell));
                }
                $data = self::rowData($brand, $values);
                if (is_string($data)) {
                    $result['errors'][] = ['line' => $line, 'message' => $data];

                    continue;
                }
                $market = $data['market_id'];
                $hash = Prompt::hashText($data['text']);
                $key = $market.':'.$hash;
                if (isset($seen[$key])) {
                    $result['errors'][] = ['line' => $line, 'message' => __('app.library.duplicate')];

                    continue;
                }
                $existing = Prompt::query()->where('market_id', $market)->where('text_hash', $hash)->first();
                if ($existing !== null) {
                    foreach (['topic_id' => 'topic', 'intent' => 'intent', 'funnel_stage' => 'funnel_stage', 'priority' => 'priority', 'is_active' => 'active'] as $field => $column) {
                        if (($values[$column] ?? '') === '') {
                            $data[$field] = $existing->getAttribute($field);
                        }
                    }
                }
                $delta = (int) $data['is_active'] - (int) ($existing->is_active ?? false);
                if ($existing?->archived_at !== null && $data['is_active']) {
                    $result['errors'][] = ['line' => $line, 'message' => __('app.library.archived_prompt')];

                    continue;
                }
                if ($delta > 0 && $limit !== null && ($counts[$market] ?? 0) + $delta > $limit) {
                    $result['errors'][] = ['line' => $line, 'message' => __('app.library.quota')];

                    continue;
                }
                $seen[$key] = true;
                $counts[$market] = ($counts[$market] ?? 0) + $delta;
                $fields = [];
                if ($existing === null) {
                    $result['created'][] = $data;
                } else {
                    foreach ($data as $field => $value) {
                        if ($value !== $existing->getAttribute($field)) {
                            $fields[$field] = [$existing->getAttribute($field), $value];
                        }
                    }
                    if ($fields === []) {
                        $result['unchanged']++;

                        continue;
                    }
                    $result['changed'][] = ['id' => $existing->id, 'text' => $existing->text, 'fields' => $fields];
                }
                if (PersonalData::in($data['text'])) {
                    $result['warnings'][] = __('app.library.personal_data_row', ['row' => $line]);
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>|string
     */
    private static function rowData(Brand $brand, array $values): array|string
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

        return [
            'market_id' => $market->id, 'text' => $text, 'topic_id' => $topicId,
            'intent' => $intent, 'funnel_stage' => $funnel, 'priority' => $priority, 'is_active' => $active,
        ];
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
}
