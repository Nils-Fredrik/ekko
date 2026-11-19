<?php

namespace App\FactCheck\Evaluation;

use App\Extraction\Evaluation\Metric;
use App\FactCheck\ClaimAssembler;
use App\FactCheck\ClaimParser;
use App\FactCheck\FactCheckCost;
use App\FactCheck\FactCheckPromptBuilder;
use App\FactCheck\Judge\FactJudge;
use App\FactCheck\Judge\FactJudgeFailed;
use App\FactCheck\ModelClaim;
use App\Models\Claim;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Evaluates the fact check against the gold set (docs/spec.md 11.6 and 18, T48). It runs the same path as
 * production, without a database: the request with the brand's facts, one call to the judge model per answer, and
 * the claim validation of ClaimAssembler. What is left is compared with the expected verdicts.
 *
 * The gold file is `tests/Fixtures/factcheck/gold.json`: cases with a brand, its facts, an answer and the expected
 * claims (`fact_id`, `verdict` supported or contradicted, and `quote`). A fact without an expected claim is a fact
 * the answer says nothing about; a supported or contradicted claim on it counts against precision.
 */
final class FactCheckEvaluator
{
    private const VERDICTS = [Claim::SUPPORTED, Claim::CONTRADICTED];

    public function __construct(
        private readonly FactCheckPromptBuilder $prompts = new FactCheckPromptBuilder,
        private readonly ClaimAssembler $claims = new ClaimAssembler,
        private readonly FactCheckCost $cost = new FactCheckCost,
    ) {}

    public function evaluate(string $goldPath, FactJudge $judge): FactCheckReport
    {
        $gold = json_decode((string) @file_get_contents($goldPath), true);
        if (! is_array($gold) || ! is_array($gold['cases'] ?? null)) {
            throw new InvalidArgumentException('The gold set file is missing or invalid.');
        }

        $verdicts = [Claim::SUPPORTED => new Metric, Claim::CONTRADICTED => new Metric];
        $byLanguage = [];
        $accuracy = [];
        $correct = $expectedTotal = $unexpected = 0;
        $failed = [];
        $tokens = [0, 0];
        $cost = BigDecimal::zero();

        foreach ($gold['cases'] as $case) {
            $language = $case['language'];
            $byLanguage[$language] = ($byLanguage[$language] ?? 0) + 1;

            // The request production builds for an answer with these facts; one call per answer.
            $request = $this->prompts->build($case['id'], $case['text'], $language, $case['brand'], $case['facts'], array_column($case['citations'], 'url'));
            $parsed = null;
            try {
                $result = $judge->judge($request);
                $parsed = ClaimParser::parse($result->text);
                $tokens[0] += $result->inputTokens;
                $tokens[1] += $result->outputTokens;
                $cost = $cost->plus($this->cost->of($result->inputTokens, $result->outputTokens));
            } catch (FactJudgeFailed) {
            }
            if ($parsed === null) {
                $failed[] = $case['id'];
            }

            $predicted = $this->predicted($this->claims->assemble($case['text'], $parsed ?? [], array_column($case['facts'], 'id')));
            $expected = [];
            foreach ($case['expected'] as $row) {
                $expected[(int) $row['fact_id']] = $row['verdict'];
            }

            foreach ($expected as $factId => $verdict) {
                $verdicts[$verdict]->expected++;
                $expectedTotal++;
                $hit = ($predicted[$factId] ?? null) === $verdict;
                $correct += $hit ? 1 : 0;
                $accuracy[$language] = [
                    'correct' => ($accuracy[$language]['correct'] ?? 0) + ($hit ? 1 : 0),
                    'total' => ($accuracy[$language]['total'] ?? 0) + 1,
                ];
            }
            foreach ($predicted as $factId => $verdict) {
                if (! in_array($verdict, self::VERDICTS, true)) {
                    continue;
                }
                $verdicts[$verdict]->predicted++;
                $verdicts[$verdict]->truePositives += ($expected[$factId] ?? null) === $verdict ? 1 : 0;
                $unexpected += isset($expected[$factId]) ? 0 : 1;
            }
        }

        ksort($accuracy);

        return new FactCheckReport(
            count($gold['cases']), $expectedTotal, $byLanguage, $verdicts, $correct, $accuracy, $unexpected, $failed,
            $tokens[0], $tokens[1], (string) $cost->toScale(8),
        );
    }

    /**
     * One verdict per fact. Two claims on a fact that disagree count as contradicted: the stricter signal is the one
     * that would start an action, so it is the one to measure.
     *
     * @param  list<ModelClaim>  $claims
     * @return array<int, string>
     */
    private function predicted(array $claims): array
    {
        $rank = [Claim::UNRELATED => 0, Claim::SUPPORTED => 1, Claim::CONTRADICTED => 2];
        $verdicts = [];
        foreach ($claims as $claim) {
            $current = $verdicts[$claim->factId] ?? null;
            if ($current === null || $rank[$claim->verdict] > $rank[$current]) {
                $verdicts[(int) $claim->factId] = $claim->verdict;
            }
        }

        return $verdicts;
    }
}
