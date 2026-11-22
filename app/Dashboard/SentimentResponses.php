<?php

namespace App\Dashboard;

use App\Metrics\DailyAggregator;
use App\Metrics\MetricsFilter;
use App\Models\Mention;
use App\Prompts\AnswerSegments;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The answers behind the sentiment figures on the competitors page (U28): the mentions of the brand or one of its
 * competitors with a sentiment, in the period and filters of that page.
 *
 * The selection follows {@see DailyAggregator} and the sentiment distribution, so the counts here are the same as
 * the n of the table: only responses of runs with status extracted, the tenant-local day of collected_at, prompts
 * of the brand's markets, and a mention with a sentiment. The sign gives the group, as in the aggregate:
 * below zero is negative, zero is neutral and above zero is positive.
 */
final class SentimentResponses
{
    public const SENTIMENTS = ['negative', 'neutral', 'positive'];

    public const PER_PAGE = 25;

    /** Characters of answer text on each side of the mention in the excerpt. */
    private const BEFORE = 160;

    private const AFTER = 240;

    /**
     * @param  'brand'|'competitor'  $entityType
     */
    public function __construct(
        private readonly MetricsFilter $filter,
        private readonly string $timezone,
        private readonly string $entityType,
        private readonly int $entityId,
    ) {}

    /**
     * Mentions per sentiment group, for the tabs.
     *
     * @return array{negative: int, neutral: int, positive: int, total: int}
     */
    public function counts(): array
    {
        $row = $this->query()
            ->toBase()
            ->selectRaw('count(*) filter (where mentions.sentiment < 0) as negative')
            ->selectRaw('count(*) filter (where mentions.sentiment = 0) as neutral')
            ->selectRaw('count(*) filter (where mentions.sentiment > 0) as positive')
            ->first();

        $negative = (int) ($row->negative ?? 0);
        $neutral = (int) ($row->neutral ?? 0);
        $positive = (int) ($row->positive ?? 0);

        return ['negative' => $negative, 'neutral' => $neutral, 'positive' => $positive,
            'total' => $negative + $neutral + $positive];
    }

    /**
     * The mentions of one group (all groups when null), newest answer first, with their response and prompt.
     *
     * @return LengthAwarePaginator<int, Mention>
     */
    public function page(?string $sentiment, int $page): LengthAwarePaginator
    {
        return $this->query()
            ->when($sentiment === 'negative', fn (Builder $q) => $q->where('mentions.sentiment', '<', 0))
            ->when($sentiment === 'neutral', fn (Builder $q) => $q->where('mentions.sentiment', '=', 0))
            ->when($sentiment === 'positive', fn (Builder $q) => $q->where('mentions.sentiment', '>', 0))
            ->select('mentions.*')
            ->with([
                'response' => fn ($response) => $response->with([
                    'mentions' => fn ($mentions) => $mentions->orderBy('first_char_offset')->orderBy('id'),
                    'run.prompt',
                ]),
            ])
            ->orderByDesc('responses.collected_at')
            ->orderByDesc('mentions.id')
            ->paginate(self::PER_PAGE, page: $page);
    }

    /**
     * The text around the mention, cut at whole words, with the mention itself apart so it can be highlighted.
     *
     * @return array{before: string, match: string, after: string, cut_start: bool, cut_end: bool}
     */
    public static function excerpt(string $answer, string $surface, ?int $offset): array
    {
        $length = mb_strlen($answer);
        $span = mb_strlen($surface);
        // A stored offset is used when it points at the name; otherwise the first copy that stands as its own word,
        // with the same word boundary as the highlighting on the prompt page.
        if ($offset === null || $offset < 0 || $span === 0 || $offset + $span > $length
            || mb_strtolower(mb_substr($answer, $offset, $span)) !== mb_strtolower($surface)) {
            $offset = AnswerSegments::findWord($answer, $surface, true);
        }
        if ($offset === null) {
            $cut = $length > self::BEFORE + self::AFTER;

            return ['before' => '', 'match' => '', 'after' => $cut ? self::wordEnd(mb_substr($answer, 0, self::BEFORE + self::AFTER)) : $answer,
                'cut_start' => false, 'cut_end' => $cut];
        }

        $start = max(0, $offset - self::BEFORE);
        $end = min($length, $offset + $span + self::AFTER);
        $before = mb_substr($answer, $start, $offset - $start);
        $after = mb_substr($answer, $offset + $span, $end - $offset - $span);

        return [
            'before' => $start > 0 ? self::wordStart($before) : $before,
            'match' => mb_substr($answer, $offset, $span),
            'after' => $end < $length ? self::wordEnd($after) : $after,
            'cut_start' => $start > 0,
            'cut_end' => $end < $length,
        ];
    }

    /**
     * The model's reason for the sentiment (U29) in the same shape as {@see excerpt()}, with the name highlighted when
     * the quote contains it. Null when the mention has no quote or it is no longer in the answer, so the page shows the
     * excerpt instead. An ellipsis marks that the quote is a part of the answer.
     *
     * @return array{before: string, match: string, after: string, cut_start: bool, cut_end: bool}|null
     */
    public static function quote(string $answer, ?string $quote, string $surface): ?array
    {
        if ($quote === null || $quote === '') {
            return null;
        }
        $at = mb_strpos($answer, $quote);
        if ($at === false) {
            return null;
        }
        $cuts = ['cut_start' => $at > 0, 'cut_end' => $at + mb_strlen($quote) < mb_strlen($answer)];
        $name = AnswerSegments::findWord($quote, $surface, true);
        if ($name === null) {
            return ['before' => $quote, 'match' => '', 'after' => '', ...$cuts];
        }
        $span = mb_strlen($surface);

        return [
            'before' => mb_substr($quote, 0, $name),
            'match' => mb_substr($quote, $name, $span),
            'after' => mb_substr($quote, $name + $span),
            ...$cuts,
        ];
    }

    /**
     * @return Builder<Mention>
     */
    private function query(): Builder
    {
        $start = CarbonImmutable::parse($this->filter->from.' 00:00:00', $this->timezone)->utc();
        $end = CarbonImmutable::parse($this->filter->to.' 00:00:00', $this->timezone)->addDay()->utc();

        return Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $this->filter->brandId)
            ->where('runs.status', 'extracted')
            ->where('responses.collected_at', '>=', $start)
            ->where('responses.collected_at', '<', $end)
            ->where('mentions.entity_type', $this->entityType)
            ->where('mentions.entity_id', $this->entityId)
            ->whereNotNull('mentions.sentiment')
            ->when($this->filter->marketIds !== [], fn (Builder $q) => $q->whereIn('prompts.market_id', $this->filter->marketIds))
            ->when($this->filter->engineKeys !== [], fn (Builder $q) => $q->whereIn('responses.engine_key', $this->filter->engineKeys))
            ->when($this->filter->topicIds !== [], fn (Builder $q) => $q->whereIn('prompts.topic_id', $this->filter->topicIds))
            ->when($this->filter->intents !== [], fn (Builder $q) => $q->whereIn('prompts.intent', $this->filter->intents));
    }

    /** Drops a word cut at the start of an excerpt. */
    private static function wordStart(string $text): string
    {
        $space = mb_strpos($text, ' ');

        return $space === false ? $text : mb_substr($text, $space + 1);
    }

    /** Drops a word cut at the end of an excerpt. */
    private static function wordEnd(string $text): string
    {
        $space = mb_strrpos($text, ' ');

        return $space === false ? $text : mb_substr($text, 0, $space);
    }
}
