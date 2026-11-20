<?php

namespace App\Console\Commands;

use App\Extraction\Evaluation\EvaluationReport;
use App\Extraction\Evaluation\GoldSetEvaluator;
use App\Extraction\Evaluation\Metric;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionNotConfiguredException;
use App\Extraction\ExtractionRunner;
use App\Extraction\RecordedExtractionClient;
use App\Extraction\RecordingExtractionClient;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Evaluates model-based extraction against the gold set (docs/spec.md 18, T20). Not part of CI: with a real
 * key it spends money and takes minutes. `--recorded` replays recorded output without network or key.
 */
class EvaluateExtraction extends Command
{
    protected $signature = 'ekko:extraction:eval
        {--gold= : Gold set file (default database/evals/extraction-gold.json)}
        {--recorded= : Replay recorded model output from this file instead of calling the API}
        {--record= : Save the model output of this run to this file, for later offline replay}';

    protected $description = 'Evaluate model-based extraction against the gold set: precision and hit rate for is_recommended and list_position';

    public function handle(): int
    {
        $gold = (string) ($this->option('gold') ?: base_path('database/evals/extraction-gold.json'));

        try {
            if ($this->option('recorded')) {
                $client = RecordedExtractionClient::fromFile((string) $this->option('recorded'));
            } else {
                $client = app(ExtractionClient::class);
            }

            $recording = null;
            if ($this->option('record')) {
                $client = $recording = new RecordingExtractionClient($client);
            }

            $report = (new GoldSetEvaluator)->evaluate($gold, new ExtractionRunner($client), function (string $batchId): void {
                $this->components->info(__('extraction.eval.waiting', ['batch' => $batchId]));
                sleep(30);
            });
        } catch (ExtractionNotConfiguredException|InvalidArgumentException $e) {
            $this->components->error($e instanceof ExtractionNotConfiguredException ? __('extraction.eval.no_key') : __('extraction.eval.bad_file'));

            return self::FAILURE;
        }

        $recording?->writeTo((string) $this->option('record'));
        $this->render($report);

        return self::SUCCESS;
    }

    private function render(EvaluationReport $report): void
    {
        $languages = collect($report->responsesByLanguage)->map(fn (int $n, string $lang): string => "{$lang} {$n}")->implode(', ');
        $this->line(__('extraction.eval.responses', ['count' => $report->responses, 'languages' => $languages]));

        $this->newLine();
        $this->table(
            [__('extraction.eval.value'), __('extraction.eval.stage'), __('extraction.eval.precision'), __('extraction.eval.hit_rate'), __('extraction.eval.counts')],
            [
                $this->row('is_recommended', __('extraction.eval.model'), $report->model['recommended']),
                $this->row('is_recommended', __('extraction.eval.assembled'), $report->assembled['recommended']),
                $this->row('list_position', __('extraction.eval.model'), $report->model['position']),
                $this->row('list_position', __('extraction.eval.assembled'), $report->assembled['position']),
            ],
        );

        foreach (['model' => $report->sourceTypes, 'assembled' => $report->storedSourceTypes] as $stage => $sources) {
            $this->line(__('extraction.eval.source_type', [
                'stage' => __('extraction.eval.'.$stage),
                'correct' => $sources['correct'], 'total' => $sources['total'],
                'rate' => $this->percent($sources['total'] === 0 ? null : $sources['correct'] / $sources['total']),
            ]));
        }
        foreach ($report->sourceTypeMisses as $miss) {
            $this->line('  '.__('extraction.eval.source_type_miss', ['miss' => $miss]));
        }
        $this->line(__('extraction.eval.retried', ['count' => $report->retried, 'failed' => count($report->failedIds)]));
        $this->line(__('extraction.eval.usage', [
            'input' => $report->inputTokens, 'output' => $report->outputTokens, 'cost' => $report->costUsd,
        ]));
    }

    /** @return list<string> */
    private function row(string $value, string $stage, Metric $metric): array
    {
        return [
            $value, $stage, $this->percent($metric->precision()), $this->percent($metric->hitRate()),
            "{$metric->truePositives}/{$metric->predicted}/{$metric->expected}",
        ];
    }

    private function percent(?float $ratio): string
    {
        return $ratio === null ? 'n/a' : number_format($ratio * 100, 1).' %';
    }
}
