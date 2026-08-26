<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Service;

use Borsche\GoogleDriveDocsBundle\Event\SheetFormattedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Model\SheetRange;
use Google\Service\Sheets;
use Google\Service\Sheets\AddBandingRequest;
use Google\Service\Sheets\AddConditionalFormatRuleRequest;
use Google\Service\Sheets\AddProtectedRangeRequest;
use Google\Service\Sheets\BandedRange;
use Google\Service\Sheets\BandingProperties;
use Google\Service\Sheets\BasicFilter;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BooleanCondition;
use Google\Service\Sheets\BooleanRule;
use Google\Service\Sheets\Border;
use Google\Service\Sheets\ClearBasicFilterRequest;
use Google\Service\Sheets\ConditionValue;
use Google\Service\Sheets\ConditionalFormatRule;
use Google\Service\Sheets\DataValidationRule;
use Google\Service\Sheets\Editors;
use Google\Service\Sheets\ProtectedRange;
use Google\Service\Sheets\SetBasicFilterRequest;
use Google\Service\Sheets\SetDataValidationRequest;
use Google\Service\Sheets\UpdateBordersRequest;
use Google\Service\Sheets\CellData;
use Google\Service\Sheets\CellFormat;
use Google\Service\Sheets\Color;
use Google\Service\Sheets\ColorStyle;
use Google\Service\Sheets\DimensionProperties;
use Google\Service\Sheets\DimensionRange;
use Google\Service\Sheets\GridProperties;
use Google\Service\Sheets\GridRange;
use Google\Service\Sheets\MergeCellsRequest;
use Google\Service\Sheets\NumberFormat;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\RepeatCellRequest;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\TextFormat;
use Google\Service\Sheets\UnmergeCellsRequest;
use Google\Service\Sheets\UpdateDimensionPropertiesRequest;
use Google\Service\Sheets\UpdateSheetPropertiesRequest;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A pending formatting pass, collected and then sent as one request.
 *
 * Making a generated report look finished takes several changes — bold the header, freeze it,
 * give the money column a number format, widen the columns. Google's batchUpdate exists to
 * carry them together, so this collects them and sends one request at apply(): either the
 * whole pass lands or none of it does.
 *
 *     $sheets->format($fileId)
 *         ->style('Q3!A1:D1', bold: true, background: '#DDE6EC', horizontalAlign: 'CENTER')
 *         ->freeze('Q3', rows: 1)
 *         ->numberFormat('Q3!D2:D', '#,##0.00')
 *         ->autoResizeColumns('Q3')
 *         ->apply();
 *
 * Ranges are A1 notation, as everywhere else in the bundle; the numeric sheet ids Google's
 * formatting calls actually want are resolved at apply() with a single lookup.
 *
 * As in DriveDocumentService, `Google\Service\Exception` propagates rather than being wrapped.
 */
class SheetFormatter
{
    public const ALIGN_LEFT   = 'LEFT';
    public const ALIGN_CENTER = 'CENTER';
    public const ALIGN_RIGHT  = 'RIGHT';

    private const ALIGNMENTS = [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT];

    /** Google rejects a column narrower than this, and a very wide one is a mistake. */
    private const MIN_PIXELS = 1;
    private const MAX_PIXELS = 2000;

    /**
     * Operations one pass may collect.
     *
     * This bundle's own ceiling, not a documented Google one: the whole pass travels as a
     * single batchUpdate, and a request that grows without bound is a runaway rather than a
     * styling pass — the same reasoning as MAX_PAGES and MAX_BATCH_RANGES elsewhere. Call
     * apply() and start another pass if a job genuinely needs more.
     */
    public const MAX_OPERATIONS = 500;

    /**
     * Pending changes. Each knows the tab it needs and how to become a Request once the
     * sheet ids are known.
     *
     * @var list<array{tab: string|null, build: callable(int): Request}>
     */
    private array $pending = [];

    public function __construct(
        private readonly Sheets $sheets,
        private readonly DriveDocumentService $drive,
        private readonly string $fileId,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Cell appearance. Only the attributes given are written — the rest of the formatting on
     * those cells is left alone, which is why each call names its own field mask.
     *
     * @param string|null $background     `#RRGGBB` or `#RGB`
     * @param string|null $horizontalAlign one of the ALIGN_* constants
     */
    public function style(
        string $range,
        ?bool $bold = null,
        ?bool $italic = null,
        ?int $fontSize = null,
        ?string $color = null,
        ?string $background = null,
        ?string $horizontalAlign = null,
        ?bool $wrapped = null
    ): self {
        $format = new CellFormat();
        $text   = new TextFormat();
        $fields = [];

        if ($bold !== null) {
            $text->setBold($bold);
            $fields[] = 'userEnteredFormat.textFormat.bold';
        }

        if ($italic !== null) {
            $text->setItalic($italic);
            $fields[] = 'userEnteredFormat.textFormat.italic';
        }

        if ($fontSize !== null) {
            $text->setFontSize($fontSize);
            $fields[] = 'userEnteredFormat.textFormat.fontSize';
        }

        if ($color !== null) {
            $text->setForegroundColorStyle(self::colour($color));
            $fields[] = 'userEnteredFormat.textFormat.foregroundColorStyle';
        }

        if ($fields !== []) {
            $format->setTextFormat($text);
        }

        if ($background !== null) {
            $format->setBackgroundColorStyle(self::colour($background));
            $fields[] = 'userEnteredFormat.backgroundColorStyle';
        }

        if ($horizontalAlign !== null) {
            if (!in_array($horizontalAlign, self::ALIGNMENTS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported alignment "%s". Allowed: %s.',
                    $horizontalAlign,
                    implode(', ', self::ALIGNMENTS)
                ));
            }

            $format->setHorizontalAlignment($horizontalAlign);
            $fields[] = 'userEnteredFormat.horizontalAlignment';
        }

        if ($wrapped !== null) {
            $format->setWrapStrategy($wrapped ? 'WRAP' : 'OVERFLOW_CELL');
            $fields[] = 'userEnteredFormat.wrapStrategy';
        }

        if ($fields === []) {
            // Nothing to change; queueing it would send Google an empty mask.
            return $this;
        }

        return $this->repeatCell($range, $format, $fields);
    }

    /**
     * A number format pattern, in Google's own syntax: `#,##0.00`, `0%`, `dd.MM.yyyy`.
     */
    public function numberFormat(string $range, string $pattern, string $type = 'NUMBER'): self
    {
        $format = new CellFormat();
        $format->setNumberFormat(new NumberFormat(['type' => $type, 'pattern' => $pattern]));

        return $this->repeatCell($range, $format, ['userEnteredFormat.numberFormat']);
    }

    /**
     * Keep the first rows or columns of a tab in view while the rest scrolls.
     *
     * Takes a tab name, not a range: a bare `Q3` in A1 notation is the cell Q3, so a range
     * would be ambiguous exactly where it matters.
     */
    public function freeze(string $tab, ?int $rows = null, ?int $columns = null): self
    {
        $grid   = new GridProperties();
        $fields = [];

        if ($rows !== null) {
            $grid->setFrozenRowCount($rows);
            $fields[] = 'gridProperties.frozenRowCount';
        }

        if ($columns !== null) {
            $grid->setFrozenColumnCount($columns);
            $fields[] = 'gridProperties.frozenColumnCount';
        }

        if ($fields === []) {
            return $this;
        }

        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $tab,
            'build' => static function (int $sheetId) use ($grid, $fields): Request {
                $properties = new SheetProperties();
                $properties->setSheetId($sheetId);
                $properties->setGridProperties($grid);

                $update = new UpdateSheetPropertiesRequest();
                $update->setProperties($properties);
                $update->setFields(implode(',', $fields));

                $request = new Request();
                $request->setUpdateSheetProperties($update);

                return $request;
            },
        ];

        return $this;
    }

    /** Widen every column of a tab to fit its content, the way double-clicking a border does. */
    public function autoResizeColumns(string $tab): self
    {
        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $tab,
            'build' => static function (int $sheetId): Request {
                $dimensions = new DimensionRange();
                $dimensions->setSheetId($sheetId);
                $dimensions->setDimension('COLUMNS');

                $resize = new Sheets\AutoResizeDimensionsRequest();
                $resize->setDimensions($dimensions);

                $request = new Request();
                $request->setAutoResizeDimensions($resize);

                return $request;
            },
        ];

        return $this;
    }

    /** An exact column width in pixels, for when auto-resize gets it wrong. */
    public function columnWidth(string $range, int $pixels): self
    {
        return $this->dimensionSize($range, 'COLUMNS', $pixels);
    }

    /** Join a block into one cell — a title spanning the table, typically. */
    public function merge(string $range, string $type = 'MERGE_ALL'): self
    {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($type): Request {
            $merge = new MergeCellsRequest();
            $merge->setRange($grid);
            $merge->setMergeType($type);

            $request = new Request();
            $request->setMergeCells($merge);

            return $request;
        });
    }

    public function unmerge(string $range): self
    {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid): Request {
            $unmerge = new UnmergeCellsRequest();
            $unmerge->setRange($grid);

            $request = new Request();
            $request->setUnmergeCells($unmerge);

            return $request;
        });
    }

    /**
     * Lines around a block, and optionally the grid inside it.
     *
     * @param string $style one of Google's border styles: SOLID, SOLID_MEDIUM, SOLID_THICK,
     *                      DASHED, DOTTED, DOUBLE, NONE
     */
    public function borders(
        string $range,
        bool $outline = true,
        bool $inner = false,
        string $style = 'SOLID',
        ?string $color = null
    ): self {
        if (!$outline && !$inner) {
            return $this;
        }

        $line = static function () use ($style, $color): Border {
            $border = new Border();
            $border->setStyle($style);

            if ($color !== null) {
                $border->setColorStyle(self::colour($color));
            }

            return $border;
        };

        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($outline, $inner, $line): Request {
            $borders = new UpdateBordersRequest();
            $borders->setRange($grid);

            if ($outline) {
                $borders->setTop($line());
                $borders->setBottom($line());
                $borders->setLeft($line());
                $borders->setRight($line());
            }

            if ($inner) {
                $borders->setInnerHorizontal($line());
                $borders->setInnerVertical($line());
            }

            $request = new Request();
            $request->setUpdateBorders($borders);

            return $request;
        });
    }

    /** An exact row height in pixels — a taller header row, typically. */
    public function rowHeight(string $range, int $pixels): self
    {
        return $this->dimensionSize($range, 'ROWS', $pixels);
    }

    /**
     * Highlight cells that meet a condition — negatives in red, overdue dates, and so on.
     *
     * The condition is Google's own vocabulary: NUMBER_LESS, NUMBER_GREATER, TEXT_CONTAINS,
     * DATE_BEFORE, BLANK, CUSTOM_FORMULA and the rest. Values are what the condition compares
     * against, as strings; for CUSTOM_FORMULA the single value is the formula itself.
     *
     * @param string[] $values
     */
    public function conditionalFormat(
        string $range,
        string $condition,
        array $values = [],
        ?string $background = null,
        ?bool $bold = null,
        ?string $color = null
    ): self {
        $format = new CellFormat();
        $text   = new TextFormat();
        $styled = false;

        if ($bold !== null) {
            $text->setBold($bold);
            $format->setTextFormat($text);
            $styled = true;
        }

        if ($color !== null) {
            $text->setForegroundColorStyle(self::colour($color));
            $format->setTextFormat($text);
            $styled = true;
        }

        if ($background !== null) {
            $format->setBackgroundColorStyle(self::colour($background));
            $styled = true;
        }

        if (!$styled) {
            throw new \InvalidArgumentException(
                'A conditional format rule that changes nothing would be invisible: '
                . 'pass at least one of background, bold or color.'
            );
        }

        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($condition, $values, $format): Request {
            $rule = new ConditionalFormatRule();
            $rule->setRanges([$grid]);
            $rule->setBooleanRule(new BooleanRule([
                'condition' => self::condition($condition, $values),
                'format'    => $format,
            ]));

            $add = new AddConditionalFormatRuleRequest();
            $add->setRule($rule);

            $request = new Request();
            $request->setAddConditionalFormatRule($add);

            return $request;
        });
    }

    /**
     * Constrain what may be typed into a range. ONE_OF_LIST with a list of values is the
     * dropdown case; NUMBER_GREATER, DATE_AFTER, TEXT_IS_EMAIL and the rest also work.
     *
     * `strict` rejects anything else outright; false only warns, which is friendlier when the
     * list is guidance rather than law.
     *
     * @param string[] $values
     */
    public function dataValidation(
        string $range,
        string $condition,
        array $values = [],
        bool $strict = true,
        bool $showDropdown = true,
        ?string $message = null
    ): self {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($condition, $values, $strict, $showDropdown, $message): Request {
            $rule = new DataValidationRule();
            $rule->setCondition(self::condition($condition, $values));
            $rule->setStrict($strict);
            $rule->setShowCustomUi($showDropdown);

            if ($message !== null) {
                $rule->setInputMessage($message);
            }

            $set = new SetDataValidationRequest();
            $set->setRange($grid);
            $set->setRule($rule);

            $request = new Request();
            $request->setSetDataValidation($set);

            return $request;
        });
    }

    /** The filter row on a table, so the reader can sort and filter it themselves. */
    public function basicFilter(string $range): self
    {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid): Request {
            $filter = new BasicFilter();
            $filter->setRange($grid);

            $set = new SetBasicFilterRequest();
            $set->setFilter($filter);

            $request = new Request();
            $request->setSetBasicFilter($set);

            return $request;
        });
    }

    public function clearBasicFilter(string $tab): self
    {
        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $tab,
            'build' => static function (int $sheetId): Request {
                $clear = new ClearBasicFilterRequest();
                $clear->setSheetId($sheetId);

                $request = new Request();
                $request->setClearBasicFilter($clear);

                return $request;
            },
        ];

        return $this;
    }

    /**
     * Lock a range so the people using the spreadsheet cannot break it — the column holding
     * the formulas, typically, which is the whole reason the document lives in Google.
     *
     * With no editors named, only the service user may still write there. There is no
     * unprotect(): removing a protection means finding the id Google assigned it, which is a
     * job for whoever is looking at the spreadsheet, not for a generator.
     *
     * @param string[] $editors e-mail addresses allowed to edit anyway
     */
    public function protect(string $range, ?string $description = null, array $editors = []): self
    {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($description, $editors): Request {
            $protected = new ProtectedRange();
            $protected->setRange($grid);

            if ($description !== null) {
                $protected->setDescription($description);
            }

            if ($editors !== []) {
                $protected->setEditors(new Editors(['users' => array_values($editors)]));
            }

            $add = new AddProtectedRangeRequest();
            $add->setProtectedRange($protected);

            $request = new Request();
            $request->setAddProtectedRange($add);

            return $request;
        });
    }

    /** Alternating row colours, which is what makes a long table readable. */
    public function bandedRows(string $range, string $first = '#FFFFFF', string $second = '#F3F3F3', ?string $header = null): self
    {
        $properties = new BandingProperties();
        $properties->setFirstBandColorStyle(self::colour($first));
        $properties->setSecondBandColorStyle(self::colour($second));

        if ($header !== null) {
            $properties->setHeaderColorStyle(self::colour($header));
        }

        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($properties): Request {
            $banded = new BandedRange();
            $banded->setRange($grid);
            $banded->setRowProperties($properties);

            $add = new AddBandingRequest();
            $add->setBandedRange($banded);

            $request = new Request();
            $request->setAddBanding($add);

            return $request;
        });
    }

    /** Keep a working tab out of the way without deleting it. */
    public function hideTab(string $tab): self
    {
        return $this->tabProperty($tab, 'hidden', true);
    }

    public function showTab(string $tab): self
    {
        return $this->tabProperty($tab, 'hidden', false);
    }

    /** The colour of the tab itself, for telling generated sheets from hand-made ones. */
    public function tabColor(string $tab, string $color): self
    {
        return $this->tabProperty($tab, 'tabColorStyle', self::colour($color));
    }

    /** How many changes are waiting to be sent. */
    public function count(): int
    {
        return count($this->pending);
    }

    /**
     * Send the whole pass. Does nothing when nothing was queued, and leaves the formatter
     * empty afterwards so a second call cannot repeat the changes.
     */
    public function apply(): void
    {
        if ($this->pending === []) {
            return;
        }

        $this->drive->assertAccess($this->fileId);

        $sheetIds = $this->sheetIds();

        if ($sheetIds === []) {
            throw new UnexpectedDriveStateException(sprintf(
                'Google described spreadsheet "%s" without a single usable tab, so there is '
                . 'nothing here to format.',
                $this->fileId
            ));
        }

        $first    = array_key_first($sheetIds);
        $requests = [];

        foreach ($this->pending as $change) {
            $tab = $change['tab'] ?? $first;

            // $tab is int for a tab titled "2024" — PHP coerced the key when the map was built.
            if ($tab === null || !array_key_exists($tab, $sheetIds)) {
                throw new \InvalidArgumentException(sprintf(
                    'This spreadsheet has no tab called "%s". Known tabs: %s.',
                    (string) $tab,
                    implode(', ', array_keys($sheetIds)) ?: 'none'
                ));
            }

            $requests[] = ($change['build'])($sheetIds[$tab]);
        }

        $request = new BatchUpdateSpreadsheetRequest();
        $request->setRequests($requests);

        $this->sheets->spreadsheets->batchUpdate($this->fileId, $request);

        $applied       = count($requests);
        $this->pending = [];

        $this->dispatcher?->dispatch(new SheetFormattedEvent($this->fileId, $applied));
    }

    /**
     * @param string[] $fields
     */
    private function repeatCell(string $range, CellFormat $format, array $fields): self
    {
        return $this->withRange($range, static function (int $sheetId, GridRange $grid) use ($format, $fields): Request {
            $cell = new CellData();
            $cell->setUserEnteredFormat($format);

            $repeat = new RepeatCellRequest();
            $repeat->setRange($grid);
            $repeat->setCell($cell);
            // The mask decides what is written. Anything it names and the cell does not set
            // is cleared, so it must list exactly the attributes that were asked for.
            $repeat->setFields(implode(',', $fields));

            $request = new Request();
            $request->setRepeatCell($repeat);

            return $request;
        });
    }

    /**
     * @param callable(int, GridRange): Request $build
     */
    private function withRange(string $range, callable $build): self
    {
        $parsed = SheetRange::fromA1($range);

        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $parsed->tab,
            'build' => static function (int $sheetId) use ($parsed, $build): Request {
                $grid = new GridRange();
                $grid->setSheetId($sheetId);

                if ($parsed->startRow !== null) {
                    $grid->setStartRowIndex($parsed->startRow);
                }

                if ($parsed->endRow !== null) {
                    $grid->setEndRowIndex($parsed->endRow);
                }

                if ($parsed->startColumn !== null) {
                    $grid->setStartColumnIndex($parsed->startColumn);
                }

                if ($parsed->endColumn !== null) {
                    $grid->setEndColumnIndex($parsed->endColumn);
                }

                return $build($sheetId, $grid);
            },
        ];

        return $this;
    }

    private function assertRoom(): void
    {
        if (count($this->pending) >= self::MAX_OPERATIONS) {
            throw new \OverflowException(sprintf(
                'A formatting pass takes at most %d operations, and this one is already full. '
                . 'Call apply() and start another pass.',
                self::MAX_OPERATIONS
            ));
        }
    }

    /**
     * @return array<string, int>
     */
    private function sheetIds(): array
    {
        return SheetTabIndex::of($this->sheets, $this->fileId);
    }

    /**
     * One tab-level property, with the field mask naming just that property.
     */
    private function tabProperty(string $tab, string $field, mixed $value): self
    {
        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $tab,
            'build' => static function (int $sheetId) use ($field, $value): Request {
                $properties = new SheetProperties();
                $properties->setSheetId($sheetId);

                if ($field === 'hidden') {
                    $properties->setHidden($value);
                } else {
                    $properties->setTabColorStyle($value);
                }

                $update = new UpdateSheetPropertiesRequest();
                $update->setProperties($properties);
                $update->setFields($field);

                $request = new Request();
                $request->setUpdateSheetProperties($update);

                return $request;
            },
        ];

        return $this;
    }

    private function dimensionSize(string $range, string $dimension, int $pixels): self
    {
        if ($pixels < self::MIN_PIXELS || $pixels > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(sprintf(
                'A size of %d pixels is outside the %d–%d this bundle allows.',
                $pixels,
                self::MIN_PIXELS,
                self::MAX_PIXELS
            ));
        }

        $parsed = SheetRange::fromA1($range);
        $rows   = $dimension === 'ROWS';

        $this->assertRoom();

        $this->pending[] = [
            'tab'   => $parsed->tab,
            'build' => static function (int $sheetId) use ($pixels, $parsed, $dimension, $rows): Request {
                $dimensions = new DimensionRange();
                $dimensions->setSheetId($sheetId);
                $dimensions->setDimension($dimension);

                $start = $rows ? $parsed->startRow : $parsed->startColumn;
                $end   = $rows ? $parsed->endRow : $parsed->endColumn;

                if ($start !== null) {
                    $dimensions->setStartIndex($start);
                }

                if ($end !== null) {
                    $dimensions->setEndIndex($end);
                }

                $update = new UpdateDimensionPropertiesRequest();
                $update->setRange($dimensions);
                $update->setProperties(new DimensionProperties(['pixelSize' => $pixels]));
                $update->setFields('pixelSize');

                $request = new Request();
                $request->setUpdateDimensionProperties($update);

                return $request;
            },
        ];

        return $this;
    }

    /**
     * @param string[] $values
     */
    private static function condition(string $type, array $values): BooleanCondition
    {
        $condition = new BooleanCondition();
        $condition->setType($type);

        if ($values !== []) {
            $condition->setValues(array_map(
                static fn (string $value): ConditionValue => new ConditionValue(['userEnteredValue' => $value]),
                array_values($values)
            ));
        }

        return $condition;
    }

    /**
     * `#RRGGBB` or `#RGB` to the shape Google wants: a ColorStyle wrapping the 0..1 floats.
     *
     * Every plain `color` field on a Sheets request is deprecated in favour of its
     * `colorStyle` twin, which also admits a theme colour. The field masks name the twin
     * accordingly — a mask and the field it sets have to agree.
     */
    private static function colour(string $hex): ColorStyle
    {
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $hex, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a colour. Use #RRGGBB or #RGB.',
                $hex
            ));
        }

        $digits = $m[1];

        if (strlen($digits) === 3) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }

        [$r, $g, $b] = array_map(
            static fn (string $pair): float => hexdec($pair) / 255,
            [substr($digits, 0, 2), substr($digits, 2, 2), substr($digits, 4, 2)]
        );

        return new ColorStyle(['rgbColor' => new Color(['red' => $r, 'green' => $g, 'blue' => $b])]);
    }
}
