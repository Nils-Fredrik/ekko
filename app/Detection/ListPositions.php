<?php

namespace App\Detection;

/**
 * Finds numbered and bulleted list items in an answer (docs/spec.md 8.1, ADR 0010).
 *
 * A list is a run of marker lines (`1.`, `1)`, `-`, `*`, `•`, `–`) with the same marker kind and indentation.
 * Deeper-indented markers and plain lines that follow without a blank line belong to the item above.
 * Blank lines do not end a list, but a plain, non-indented line after a blank line does. A numbered list
 * that was ended that way continues when the next marker is the next number (1. Volvo, a paragraph about it,
 * 2. Saab, ...), and the paragraphs belong to the item above. Headings like `### 2. Saab` count as markers.
 * The position is the item's 1-based ordinal in its list, which equals the written number.
 */
final class ListPositions
{
    private const MARKER = '/^(?<indent>[ \t]*)(?:(?:#{1,6}[ \t]+)?(?:\*\*)?(?<num>\d{1,3})[.)]|(?<bullet>[-*+•·–—]))[ \t]+\S/u';

    /**
     * @return list<array{start: int, end: int, position: int}> item ranges in code points of $text
     */
    public function items(string $text): array
    {
        $lines = preg_split('/(?<=\n)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($lines === false) {
            return [];
        }

        $items = [];
        $block = null;
        $paused = null;
        $blankPending = false;
        $offset = 0;
        $lastContentEnd = 0;

        foreach ($lines as $line) {
            $lineStart = $offset;
            $offset += mb_strlen($line);
            $content = rtrim($line, "\r\n");

            if (trim($content) === '') {
                $blankPending = true;

                continue;
            }

            $contentEnd = $lineStart + mb_strlen($content);

            if (preg_match(self::MARKER, $content, $marker) === 1) {
                $indent = $this->indentWidth($marker['indent']);
                $kind = ($marker['num'] ?? '') !== '' ? 'num' : 'bullet';

                if ($block !== null && $indent > $block['indent']) {
                    $this->extend($items, $contentEnd);
                } elseif ($block !== null && $indent === $block['indent'] && $kind === $block['kind']) {
                    $block['count']++;
                    $items[] = ['start' => $lineStart, 'end' => $contentEnd, 'position' => $block['count']];
                } elseif ($block === null && $paused !== null && $kind === 'num' && $indent === $paused['indent'] && (int) $marker['num'] === $paused['count'] + 1) {
                    $this->extend($items, $lastContentEnd);
                    $block = $paused;
                    $block['count']++;
                    $paused = null;
                    $items[] = ['start' => $lineStart, 'end' => $contentEnd, 'position' => $block['count']];
                } else {
                    $block = ['indent' => $indent, 'kind' => $kind, 'count' => 1];
                    $paused = null;
                    $items[] = ['start' => $lineStart, 'end' => $contentEnd, 'position' => 1];
                }
                $blankPending = false;
                $lastContentEnd = $contentEnd;

                continue;
            }

            $indent = $this->indentWidth(substr($content, 0, strlen($content) - strlen(ltrim($content))));

            if ($block !== null && (! $blankPending || $indent > $block['indent'])) {
                $this->extend($items, $contentEnd);
            } elseif ($block !== null) {
                $paused = $block['kind'] === 'num' ? $block : null;
                $block = null;
            }
            $blankPending = false;
            $lastContentEnd = $contentEnd;
        }

        return $items;
    }

    /**
     * Position of the list item that contains an offset, or null outside any list item.
     *
     * @param  list<array{start: int, end: int, position: int}>  $items
     */
    public function positionAt(array $items, int $offset): ?int
    {
        foreach ($items as $item) {
            if ($offset >= $item['start'] && $offset < $item['end']) {
                return $item['position'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{start: int, end: int, position: int}>  $items
     */
    private function extend(array &$items, int $lineEnd): void
    {
        $last = array_key_last($items);
        if ($last !== null) {
            $items[$last]['end'] = $lineEnd;
        }
    }

    private function indentWidth(string $whitespace): int
    {
        return strlen(str_replace("\t", '    ', $whitespace));
    }
}
