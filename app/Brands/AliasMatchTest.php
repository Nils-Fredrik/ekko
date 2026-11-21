<?php

namespace App\Brands;

use App\Detection\AliasPattern;
use App\Detection\AnswerExcerpt;
use App\Detection\DetectedMention;
use App\Detection\EntityMatcher;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Mention;
use App\Models\Response;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AliasMatchTest
{
    public function __construct(private EntityMatcher $matcher) {}

    /** @return array<string, mixed> */
    public function preview(Brand $brand, string $target, string $alias, string $mode): array
    {
        $pattern = $target === 'brand'
            ? new AliasPattern('brand', $brand->id, $alias, $mode)
            : new AliasPattern('competitor', $brand->competitors()->findOrFail(substr($target, strlen('competitor:')))->id, $alias, $mode);
        $now = CarbonImmutable::now('UTC');
        $answers = Response::query()
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $brand->id)
            ->whereBetween('responses.collected_at', [$now->subDays(30), $now])
            ->orderByDesc('responses.collected_at')->orderByDesc('responses.id');

        // Freeze the newest 2,000 IDs. Load answer text and existing classifications only one batch at a time.
        $ids = (clone $answers)->limit(2000)->pluck('responses.id');
        $matches = $scanned = 0;
        $samples = $conflicts = [];
        foreach ($ids->chunk(40) as $batch) {
            $rows = (clone $answers)->whereIntegerInRaw('responses.id', $batch)
                ->with(['mentions' => fn ($q) => $q->whereIn('entity_type', ['brand', 'competitor'])])
                ->get(['responses.id', 'responses.answer_text', 'responses.collected_at', 'responses.engine_key',
                    'markets.id as market_id', 'markets.country', 'markets.location_label']);
            foreach ($rows as $row) {
                $scanned++;
                $text = (string) $row->answer_text;
                $match = $this->matcher->detect($text, [$pattern])[0] ?? null;
                if ($match === null) {
                    continue;
                }
                $matches++;
                if (count($samples) < 3) {
                    $samples[] = [
                        'excerpt' => AnswerExcerpt::around($text, $match->surfaceName, $match->firstCharOffset),
                        'engine' => $row->engine_key,
                        'market' => ['id' => (int) $row->getAttribute('market_id'), 'label' => $row->getAttribute('location_label') ?: $row->getAttribute('country')],
                        'date' => $row->collected_at?->toIso8601String(),
                    ];
                }
                foreach ($this->overlaps($text, $match, $row->mentions) as $other) {
                    $key = $other->entity_type.':'.$other->entity_id;
                    $conflicts[$key] ??= ['type' => $other->entity_type, 'id' => $other->entity_id, 'name' => $other->surface_name, 'count' => 0];
                    $conflicts[$key]['count']++;
                }
            }
        }

        return ['matches' => $matches, 'scanned' => $scanned, 'window_days' => 30, 'samples' => $samples, 'conflicts' => $this->names($conflicts)];
    }

    /** @param Collection<int, Mention> $mentions
     * @return array<string, Mention>
     */
    private function overlaps(string $text, DetectedMention $match, Collection $mentions): array
    {
        $end = $match->firstCharOffset + mb_strlen($match->surfaceName);
        $others = [];
        foreach ($mentions as $other) {
            if ($other->entity_type === $match->entityType && (int) $other->entity_id === $match->entityId) {
                continue;
            }
            $surface = $other->surface_name;
            $length = mb_strlen($surface);
            $start = $other->first_char_offset;
            if ($length === 0) {
                continue;
            }
            if ($start === null || mb_strtolower(mb_substr($text, $start, $length)) !== mb_strtolower($surface)) {
                $start = mb_stripos($text, $surface);
            }
            if ($start !== false && $start < $end && $match->firstCharOffset < $start + $length) {
                $others[$other->entity_type.':'.$other->entity_id] = $other;
            }
        }

        return $others;
    }

    /** @param array<string, array{type: string, id: int|null, name: string, count: int}> $conflicts
     * @return list<array{type: string, name: string, count: int}>
     */
    private function names(array $conflicts): array
    {
        $results = [];
        foreach (['brand' => Brand::class, 'competitor' => Competitor::class] as $type => $model) {
            $items = collect($conflicts)->where('type', $type);
            $names = $model::query()->whereKey($items->pluck('id')->all())->pluck('name', 'id');
            foreach ($items as $item) {
                $results[] = ['type' => $type, 'name' => (string) $names->get($item['id'], $item['name']), 'count' => $item['count']];
            }
        }
        usort($results, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return $results;
    }
}
