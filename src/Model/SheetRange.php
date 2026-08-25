<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * A range of a spreadsheet, parsed out of A1 notation.
 *
 * Google's value calls take A1 notation as a string, but its formatting calls want a
 * `GridRange` — a numeric sheet id and zero-based, half-open indices. This is the bridge:
 * callers keep writing `'Q3!A1:D10'` and never meet an index.
 *
 * Indices follow GridRange exactly: **start included, end excluded**, so `A1` is rows 0..1
 * and columns 0..1. A null means unbounded on that side — `A:D` has no rows, `D2:D` has no
 * end row, a bare tab name has neither.
 */
final class SheetRange
{
    public function __construct(
        /** Null when the notation named no tab; the caller decides which one that means. */
        public readonly ?string $tab,
        public readonly ?int $startRow,
        public readonly ?int $endRow,
        public readonly ?int $startColumn,
        public readonly ?int $endColumn,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the notation cannot be read
     */
    public static function fromA1(string $a1): self
    {
        $a1 = trim($a1);

        if ($a1 === '') {
            throw new \InvalidArgumentException('An empty string is not a range.');
        }

        [$tab, $cells] = self::split($a1, $a1);

        if ($cells === null) {
            return new self($tab, null, null, null, null);
        }

        $parts = explode(':', $cells);

        if (count($parts) > 2) {
            throw new \InvalidArgumentException(sprintf('"%s" has more than one colon.', $a1));
        }

        [$fromColumn, $fromRow] = self::cell($parts[0], $a1);
        [$toColumn, $toRow]     = count($parts) === 2 ? self::cell($parts[1], $a1) : [$fromColumn, $fromRow];

        // A reversed range names the same block; Google only accepts it the right way round.
        [$firstRow, $lastRow]       = self::span($fromRow, $toRow);
        [$firstColumn, $lastColumn] = self::span($fromColumn, $toColumn);

        return new self(
            $tab,
            $firstRow === null ? null : $firstRow - 1,
            $lastRow,
            $firstColumn,
            $lastColumn === null ? null : $lastColumn + 1,
        );
    }

    /** The tab this range names, or the given fallback when it names none. */
    public function tabOr(string $fallback): string
    {
        return $this->tab ?? $fallback;
    }

    /** Zero-based index of a spreadsheet column: A is 0, Z is 25, AA is 26. */
    public static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }

    /**
     * Splits "tab!cells" into its two halves, honouring the quoting Google requires for a
     * name with a space, an apostrophe or a bang in it.
     *
     * @return array{0: string|null, 1: string|null} tab (null when unnamed), cells (null for a whole tab)
     */
    private static function split(string $a1, string $original): array
    {
        if (str_starts_with($a1, "'")) {
            $end = self::closingQuote($a1, $original);
            $tab = str_replace("''", "'", substr($a1, 1, $end - 1));
            $rest = substr($a1, $end + 1);

            if ($rest === '') {
                return [$tab, null];
            }

            if (!str_starts_with($rest, '!') || $rest === '!') {
                throw new \InvalidArgumentException(sprintf('"%s" is not a readable range.', $original));
            }

            return [$tab, substr($rest, 1)];
        }

        $bang = strrpos($a1, '!');

        if ($bang === false) {
            // No bang at all: either a bare tab name or a bare cell reference.
            return self::looksLikeCells($a1) ? [null, $a1] : [$a1, null];
        }

        $tab   = substr($a1, 0, $bang);
        $cells = substr($a1, $bang + 1);

        if ($tab === '' || $cells === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is not a readable range.', $original));
        }

        return [$tab, $cells];
    }

    private static function closingQuote(string $a1, string $original): int
    {
        $length = strlen($a1);

        for ($i = 1; $i < $length; ++$i) {
            if ($a1[$i] !== "'") {
                continue;
            }

            // A doubled apostrophe is an escaped one and does not close the name.
            if (($a1[$i + 1] ?? '') === "'") {
                ++$i;
                continue;
            }

            return $i;
        }

        throw new \InvalidArgumentException(sprintf('"%s" opens a quoted tab name it never closes.', $original));
    }

    /**
     * Whether a bang-less string reads as a cell reference rather than a tab name.
     *
     * `Q3` is both a valid cell and a valid tab name, and Google resolves it as the cell —
     * so this does too, rather than guessing. Name such a tab in quotes ("'Q3'") or use the
     * methods that take a tab name instead of a range.
     */
    private static function looksLikeCells(string $candidate): bool
    {
        return preg_match('/^(?:[A-Za-z]+[0-9]*|[0-9]+)(?::(?:[A-Za-z]+[0-9]*|[0-9]+))?$/', $candidate) === 1
            && preg_match('/^[A-Za-z]+$/', $candidate) !== 1;
    }

    /**
     * One end of a range: optional column letters, optional row number.
     *
     * @return array{0: int|null, 1: int|null} column index, row number (one-based)
     */
    private static function cell(string $part, string $original): array
    {
        if (preg_match('/^([A-Za-z]*)([0-9]*)$/', trim($part), $m) !== 1 || $m[0] === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is not a readable range.', $original));
        }

        $row = $m[2] === '' ? null : (int) $m[2];

        if ($row === 0) {
            throw new \InvalidArgumentException(sprintf('"%s" names row 0; rows start at 1.', $original));
        }

        return [$m[1] === '' ? null : self::columnIndex($m[1]), $row];
    }

    /**
     * The two ends of one dimension, in order, each null when that side is unbounded.
     *
     * A missing end is not the same as a point: "D2:D" runs from row 2 downwards with no
     * bottom, and "A:D10" has no top. Only when both ends are given is the pair ordered,
     * so a reversed range like D10:A1 still describes the block A1:D10.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function span(?int $from, ?int $to): array
    {
        if ($from === null || $to === null) {
            return [$from, $to];
        }

        return [min($from, $to), max($from, $to)];
    }
}
