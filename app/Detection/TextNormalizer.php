<?php

namespace App\Detection;

use Normalizer;

/**
 * Normalization for deterministic detection (docs/spec.md 8.1, ADR 0010), tuned for nb, nn and sv.
 *
 * Always: Unicode NFKC, invisible and control characters removed, every run of whitespace becomes one
 * space, typographic apostrophes become ', and a lowercase form is kept next to the cased form.
 *
 * With $foldDiacritics (alias matching): ö/ø and ä/æ are the same letter (Björn = Bjørn), "aa" equals å
 * (Aasen = Åsen), and other accents are dropped (café = cafe). å, æ and ø otherwise survive. Without it
 * (regex aliases) the text is only NFKC-normalized, so a pattern sees the letters as written.
 */
final class TextNormalizer
{
    private const APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "\u{00B4}", '`'];

    public function normalize(string $text, bool $foldDiacritics = true): NormalizedText
    {
        $original = mb_scrub($text, 'UTF-8');
        preg_match_all('/\X/u', $original, $matches);
        /** @var list<string> $graphemes */
        $graphemes = $matches[0];

        $out = new class
        {
            public string $cased = '';

            public string $lower = '';

            /** @var list<int> */
            public array $starts = [];

            /** @var list<int> */
            public array $ends = [];

            /** @var array<int, int> */
            public array $charAtByte = [];

            public int $count = 0;

            public function add(string $cased, string $lower, int $start, int $end): void
            {
                if (strlen($lower) !== strlen($cased)) {
                    $lower = $cased;
                }
                $this->charAtByte[strlen($this->cased)] = $this->count;
                $this->cased .= $cased;
                $this->lower .= $lower;
                $this->starts[] = $start;
                $this->ends[] = $end;
                $this->count++;
            }
        };

        $offset = 0;
        $lastWasSpace = false;
        $total = count($graphemes);

        for ($i = 0; $i < $total; $i++) {
            $grapheme = $graphemes[$i];
            $start = $offset;
            $offset += mb_strlen($grapheme);
            $end = $offset;

            if (preg_match('/^\s+$/u', $grapheme) === 1) {
                if ($lastWasSpace) {
                    $out->ends[$out->count - 1] = $end;
                } else {
                    $out->add(' ', ' ', $start, $end);
                    $lastWasSpace = true;
                }

                continue;
            }

            if ($foldDiacritics && ($grapheme === 'a' || $grapheme === 'A') && ($graphemes[$i + 1] ?? '') !== '' && strtolower($graphemes[$i + 1]) === 'a') {
                $i++;
                $offset += mb_strlen($graphemes[$i]);
                $out->add($grapheme === 'A' ? 'Å' : 'å', 'å', $start, $offset);
                $lastWasSpace = false;

                continue;
            }

            $normalized = Normalizer::normalize($grapheme, Normalizer::FORM_KC);
            $normalized = $normalized === false ? $grapheme : $normalized;
            $normalized = (string) preg_replace('/[\p{Cf}\p{Cc}]/u', '', $normalized);
            $normalized = str_replace(self::APOSTROPHES, "'", $normalized);

            if ($foldDiacritics) {
                $normalized = $this->fold($normalized);
            }

            foreach (mb_str_split($normalized) as $char) {
                $out->add($char, mb_strtolower($char), $start, $end);
                $lastWasSpace = false;
            }
        }

        $out->charAtByte[strlen($out->cased)] = $out->count;

        return new NormalizedText($original, $out->cased, $out->lower, $out->starts, $out->ends, $out->charAtByte);
    }

    private function fold(string $text): string
    {
        $text = strtr($text, ['ö' => 'ø', 'Ö' => 'Ø', 'ä' => 'æ', 'Ä' => 'Æ']);
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        if ($decomposed === false) {
            return $text;
        }

        // Keep the ring above a/A (å); drop every other combining mark.
        $stripped = (string) preg_replace('/(?<![aA])\p{Mn}|(?<=[aA])(?!\x{030A})\p{Mn}/u', '', $decomposed);
        $composed = Normalizer::normalize($stripped, Normalizer::FORM_C);

        return $composed === false ? $text : $composed;
    }
}
