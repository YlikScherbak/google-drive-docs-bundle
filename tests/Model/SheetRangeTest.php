<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Model;

use Borsche\GoogleDriveDocsBundle\Model\SheetRange;
use PHPUnit\Framework\TestCase;

final class SheetRangeTest extends TestCase
{
    /**
     * @dataProvider a1Notations
     */
    public function testItParsesA1Notation(
        string $a1,
        ?string $tab,
        ?int $startRow,
        ?int $endRow,
        ?int $startColumn,
        ?int $endColumn
    ): void {
        $range = SheetRange::fromA1($a1);

        self::assertSame($tab, $range->tab, 'tab');
        self::assertSame($startRow, $range->startRow, 'startRow');
        self::assertSame($endRow, $range->endRow, 'endRow');
        self::assertSame($startColumn, $range->startColumn, 'startColumn');
        self::assertSame($endColumn, $range->endColumn, 'endColumn');
    }

    /**
     * Indices are zero-based and half-open, the way Google's GridRange wants them:
     * start included, end excluded. A1 is therefore rows 0..1 and columns 0..1.
     *
     * @return iterable<string, array{0: string, 1: string|null, 2: int|null, 3: int|null, 4: int|null, 5: int|null}>
     */
    public static function a1Notations(): iterable
    {
        yield 'a block'              => ['Q3!A1:D10', 'Q3', 0, 10, 0, 4];
        yield 'a single cell'        => ['Q3!B2', 'Q3', 1, 2, 1, 2];
        yield 'no tab at all'        => ['A1:B2', null, 0, 2, 0, 2];
        yield 'a whole tab'          => ['Summary', 'Summary', null, null, null, null];
        // Q3 is both a cell and a plausible tab name. Google reads it as the cell; so do we.
        yield 'a bare cell-like name' => ['Q3', null, 2, 3, 16, 17];
        yield 'quote it to mean the tab' => ["'Q3'", 'Q3', null, null, null, null];
        yield 'whole columns'        => ['Q3!A:D', 'Q3', null, null, 0, 4];
        yield 'whole rows'           => ['Q3!2:5', 'Q3', 1, 5, null, null];
        yield 'open ended downwards' => ['Q3!D2:D', 'Q3', 1, null, 3, 4];
        yield 'open ended upwards'   => ['Q3!A:D10', 'Q3', null, 10, 0, 4];
        yield 'one whole column'     => ['Q3!C', 'Q3', null, null, 2, 3];
        yield 'a quoted tab'         => ["'My Sheet'!A1:B2", 'My Sheet', 0, 2, 0, 2];
        yield 'a doubled apostrophe' => ["'Bob''s'!A1", "Bob's", 0, 1, 0, 1];
        yield 'a quoted whole tab'   => ["'My Sheet'", 'My Sheet', null, null, null, null];
        yield 'a bang in the name'   => ["'Wow! Sheet'!A1", 'Wow! Sheet', 0, 1, 0, 1];
        yield 'lower case letters'   => ['Q3!a1:b2', 'Q3', 0, 2, 0, 2];
        yield 'two letter column'    => ['Q3!AA1', 'Q3', 0, 1, 26, 27];
        yield 'the 27th column'      => ['Q3!AB1:AB1', 'Q3', 0, 1, 27, 28];
        yield 'a wide span'          => ['Q3!A1:ZZ999', 'Q3', 0, 999, 0, 702];
        yield 'surrounding spaces'   => ['  Q3!A1:B2  ', 'Q3', 0, 2, 0, 2];
    }

    /**
     * @dataProvider malformed
     */
    public function testItRefusesWhatItCannotParse(string $a1): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SheetRange::fromA1($a1);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty'                 => [''];
        yield 'only spaces'           => ['   '];
        yield 'only a bang'           => ['!'];
        yield 'an empty cell part'    => ['Q3!'];
        yield 'three parts'           => ['Q3!A1:B2:C3'];
        yield 'a digit before letter' => ['Q3!1A'];
        yield 'an unclosed quote'     => ["'My Sheet!A1"];
        yield 'row zero'              => ['Q3!A0'];
        yield 'a stray character'     => ['Q3!A1:B2!'];
    }

    public function testAReversedRangeIsNormalised(): void
    {
        // D10:A1 means the same block as A1:D10; Google rejects the reversed form.
        $range = SheetRange::fromA1('Q3!D10:A1');

        self::assertSame(0, $range->startRow);
        self::assertSame(10, $range->endRow);
        self::assertSame(0, $range->startColumn);
        self::assertSame(4, $range->endColumn);
    }

    public function testItKeepsTheTabItWasGivenWhenAskedToDefault(): void
    {
        self::assertSame('Q3', SheetRange::fromA1('Q3!A1')->tabOr('Summary'));
        self::assertSame('Summary', SheetRange::fromA1('A1')->tabOr('Summary'));
    }

    /**
     * @dataProvider columnNames
     */
    public function testItConvertsColumnLettersToIndices(string $letters, int $expected): void
    {
        self::assertSame($expected, SheetRange::columnIndex($letters));
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function columnNames(): iterable
    {
        yield 'A'  => ['A', 0];
        yield 'Z'  => ['Z', 25];
        yield 'AA' => ['AA', 26];
        yield 'AZ' => ['AZ', 51];
        yield 'BA' => ['BA', 52];
        yield 'ZZ' => ['ZZ', 701];
    }
}
