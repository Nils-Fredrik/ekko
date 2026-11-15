<?php

namespace App\Detection;

/**
 * Answer text after normalization (docs/spec.md 8.1), with a map from every normalized character back
 * to the original text. Offsets are Unicode code points in the original text, not bytes.
 *
 * $cased and $lower always have the same byte layout, so a byte offset found in either maps to the same
 * character.
 */
final class NormalizedText
{
    /**
     * @param  list<int>  $starts  original start offset (code points) per normalized character
     * @param  list<int>  $ends  original end offset, exclusive, per normalized character
     * @param  array<int, int>  $charAtByte  normalized character index per byte offset (the total length maps to the character count)
     */
    public function __construct(
        public readonly string $original,
        public readonly string $cased,
        public readonly string $lower,
        public readonly array $starts,
        public readonly array $ends,
        public readonly array $charAtByte,
    ) {}

    public function isEmpty(): bool
    {
        return $this->cased === '';
    }

    /**
     * Maps a byte range in the normalized string to a code point range in the original text.
     *
     * @return array{0: int, 1: int}|null
     */
    public function originalRange(int $byteStart, int $byteEnd): ?array
    {
        if ($byteEnd <= $byteStart || ! isset($this->charAtByte[$byteStart], $this->charAtByte[$byteEnd])) {
            return null;
        }

        $firstChar = $this->charAtByte[$byteStart];
        $lastChar = $this->charAtByte[$byteEnd] - 1;

        return [$this->starts[$firstChar], $this->ends[$lastChar]];
    }

    public function originalSlice(int $start, int $end): string
    {
        return mb_substr($this->original, $start, $end - $start);
    }
}
