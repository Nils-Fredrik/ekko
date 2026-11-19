<?php

namespace App\Console\Commands;

use App\FactCheck\Evaluation\FactCheckEvaluator;
use App\FactCheck\Evaluation\FactCheckReport;
use App\FactCheck\Judge\FactJudge;
use App\FactCheck\Judge\FactJudgeFailed;
use App\FactCheck\Judge\RecordedFactJudge;
use App\FactCheck\Judge\RecordingFactJudge;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Evaluates the fact check against the gold set (docs/spec.md 11.6 and 18, T48), with the model in
 * ekko.anthropic.factcheck.model. Not part of CI: with a real key it spends money. `--recorded` replays recorded output without network or key, `--record` saves the output of a real run.
 */
class EvaluateFactCheck extends Command
{
    protected $signature = 'ekko:factcheck:eval
        {--gold= : Gold set file (default tests/Fixtures/factcheck/gold.json)}
        {--recorded= : Replay recorded model output from this file instead of calling the API}
        {--record= : Save the model output of this run to this file, for later offline replay}';

    protected $description = 'Evaluate the fact check against the gold set: accuracy, precision and hit rate for supported and contradicted';

    public function handle(): int
    {
        $gold = (string) ($this->option('gold') ?: base_path('tests/Fixtures/factcheck/gold.json'));

        try {
            $judge = $this->option('recorded')
                ? RecordedFactJudge::fromFile((string) $this->option('recorded'))
                : app(FactJudge::class);

            $recording = null;
            if ($this->option('record')) {
                $judge = $recording = new RecordingFactJudge($judge);
            }

            $report = (new FactCheckEvaluator)->evaluate($gold, $judge);
        } catch (FactJudgeFailed|InvalidArgumentException $e) {
            $this->components->error($e instanceof FactJudgeFailed ? __('fact_check.eval.no_key') : __('fact_check.eval.bad_file'));

            return self::FAILURE;
        }

        $recording?->writeTo((string) $this->option('record'));
        $this->render($report);

        return self::SUCCESS;
    }

    private function render(FactCheckReport $report): void
    {
        $languages = collect($report->casesByLanguage)->map(fn (int $n, string $lang): string => "{$lang} {$n}")->implode(', ');
        $this->line(__('fact_check.eval.cases', ['count' => $report->cases, 'claims' => $report->expectedClaims, 'languages' => $languages]));

        $this->newLine();
        $this->table(
            [__('fact_check.eval.verdict'), __('fact_check.eval.precision'), __('fact_check.eval.hit_rate'), __('fact_check.eval.counts')],
            collect($report->verdicts)->map(fn ($metric, string $verdict): array => [
                $verdict, $this->percent($metric->precision()), $this->percent($metric->hitRate()),
                "{$metric->truePositives}/{$metric->predicted}/{$metric->expected}",
            ])->values()->all(),
        );

        $this->line(__('fact_check.eval.accuracy', ['correct' => $report->correct, 'total' => $report->expectedClaims, 'rate' => $this->percent($report->accuracy())]));
        $this->line(__('fact_check.eval.by_language', ['languages' => collect($report->accuracyByLanguage)->map(
            fn (array $row, string $lang): string => "{$lang} ".$this->percent($row['total'] === 0 ? null : $row['correct'] / $row['total']),
        )->implode(', ')]));
        $this->line(__('fact_check.eval.false_claims', ['count' => $report->unexpectedClaims]));
        $this->line(__('fact_check.eval.failed', ['failed' => count($report->failedIds)]));
        $this->line(__('fact_check.eval.usage', ['input' => $report->inputTokens, 'output' => $report->outputTokens, 'cost' => $report->costUsd]));
    }

    private function percent(?float $ratio): string
    {
        return $ratio === null ? 'n/a' : number_format($ratio * 100, 1).' %';
    }
}
