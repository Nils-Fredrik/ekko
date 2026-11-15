<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Tenancy\TenantContext;

/**
 * CSV import and export of prompts (docs/spec.md 14.2). Columns are text, market (country),
 * location, topic, intent, funnel_stage, priority and active.
 */
final class PromptCsv
{
    /** @var list<string> */
    public const COLUMNS = ['text', 'market', 'location', 'topic', 'intent', 'funnel_stage', 'priority', 'active'];

    public static function export(Brand $brand): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS);

        $prompts = Prompt::query()
            ->whereIn('market_id', $brand->markets->pluck('id'))
            ->with(['market', 'topic'])
            ->orderBy('id')
            ->get();

        foreach ($prompts as $prompt) {
            if ($prompt->market === null) {
                continue;
            }

            fputcsv($handle, [
                $prompt->text,
                $prompt->market->country,
                $prompt->market->location_label ?? '',
                $prompt->topic_id === null ? '' : $prompt->topic->name,
                $prompt->intent,
                $prompt->funnel_stage,
                $prompt->priority,
                $prompt->is_active ? '1' : '0',
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
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $result['errors'][] = __('app.library.bad_header');

            return $result;
        }

        fwrite($handle, ltrim($csv, "\xEF\xBB\xBF"));
        rewind($handle);
        $header = fgetcsv($handle);
        $columns = is_array($header) ? array_map(fn ($column) => strtolower(trim((string) $column)), $header) : [];

        if (! in_array('text', $columns, true) || ! in_array('market', $columns, true)) {
            fclose($handle);
            $result['errors'][] = __('app.library.bad_header');

            return $result;
        }

        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($row === [null]) {
                continue;
            }
            if ($rowNumber > 1001) {
                $result['errors'][] = __('app.library.row_error', ['row' => $rowNumber, 'message' => __('app.library.quota')]);
                break;
            }

            $values = [];
            foreach ($columns as $index => $column) {
                $values[$column] = trim((string) ($row[$index] ?? ''));
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
        if ($text === '') {
            return __('app.library.invalid_row');
        }

        $market = self::market($brand, $values['market'] ?? '', $values['location'] ?? '');
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

        $hash = Prompt::hashText($text);
        if (Prompt::query()->where('market_id', $market->id)->where('text_hash', $hash)->exists()) {
            return __('app.library.duplicate');
        }

        if (! self::withinQuota($market->id)) {
            return __('app.library.quota');
        }

        Prompt::query()->create([
            'market_id' => $market->id,
            'topic_id' => $topicId,
            'text' => $text,
            'intent' => $intent,
            'funnel_stage' => $funnel,
            'priority' => $priority,
            'is_active' => ! in_array(strtolower($values['active'] ?? '1'), ['0', 'false', 'no'], true),
            'text_hash' => $hash,
        ]);

        return null;
    }

    private static function market(Brand $brand, string $country, string $location): Market|string
    {
        $country = strtoupper($country);
        $matches = $brand->markets->filter(function (Market $market) use ($country, $location): bool {
            if (strtoupper($market->country) !== $country) {
                return false;
            }

            if ($location === '') {
                return true;
            }

            return (string) $market->location_label === $location;
        });

        if ($matches->isEmpty()) {
            return __('app.library.unknown_market');
        }

        if ($location === '' && $matches->count() > 1) {
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

    private static function withinQuota(int $marketId): bool
    {
        $tenantId = app(TenantContext::class)->id();
        $plan = $tenantId === null ? null : Team::query()->find($tenantId)?->plan;
        $max = $plan instanceof Plan ? $plan->max_prompts_per_market : null;

        if ($max === null) {
            return true;
        }

        return Prompt::query()->where('market_id', $marketId)->count() < $max;
    }
}
