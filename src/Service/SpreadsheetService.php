<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Service;

use Borsche\GoogleDriveDocsBundle\Event\SheetRangeClearedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetRowsAppendedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetValuesUpdatedEvent;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\ValueRange;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The contents of a Google Sheet: reading cells, writing them, appending rows.
 *
 * DriveDocumentService owns the file — where it lives, who may see it. This owns what is
 * inside one, which is the other half of using a Shared Drive as a workspace: take a
 * template, fill it with the application's data, hand the user a link to the live editor.
 *
 * Access is not decided here. Every call asks DriveDocumentService, so a spreadsheet's
 * contents are exactly as reachable as the spreadsheet itself — reads included.
 */
class SpreadsheetService
{
    /**
     * Values are stored exactly as given: "=SUM(A1:A2)" stays that text, "007" stays "007".
     *
     * The default, and deliberately so. Under INPUT_AS_TYPED any string coming from a user
     * that happens to start with "=" becomes a live formula in a document other people open
     * — Google Sheets' flavour of formula injection.
     */
    public const INPUT_RAW = 'RAW';

    /**
     * Values are interpreted as if a person had typed them: formulas evaluate, "01.09.2026"
     * becomes a date, "007" becomes the number 7. Use it where the input is yours, not a user's.
     */
    public const INPUT_AS_TYPED = 'USER_ENTERED';

    /** Cells as the user sees them: thousands separators, currency symbols, formatted dates. */
    public const RENDER_FORMATTED = 'FORMATTED_VALUE';

    /**
     * Underlying values without formatting — what to use for arithmetic. Numbers come back
     * as int/float, checkboxes as bool, dates as Sheets' serial day number (see read()).
     */
    public const RENDER_RAW = 'UNFORMATTED_VALUE';

    /** The formulas themselves rather than what they evaluate to. */
    public const RENDER_FORMULA = 'FORMULA';

    /**
     * How many ranges one batchGet / batchUpdate call may carry.
     *
     * The bundle's own ceiling, not a documented Google limit. It earns its place on the
     * read side: batchGet takes its ranges as a query parameter, so a long list becomes a
     * long URL that Google or a proxy in front of it can reject with an opaque error.
     * batchUpdate sends them in the body and has no such constraint, so there the cap is
     * only a runaway guard — the same reasoning as MAX_PAGES on the Drive side.
     */
    public const MAX_BATCH_RANGES = 100;

    private const INPUT_MODES = [self::INPUT_RAW, self::INPUT_AS_TYPED];

    private const RENDER_MODES = [self::RENDER_FORMATTED, self::RENDER_RAW, self::RENDER_FORMULA];

    public function __construct(
        private readonly Sheets $sheets,
        private readonly DriveDocumentService $drive,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * The tabs of a spreadsheet, in the order they appear, as `['title' => ..., 'sheetId' => ...]`.
     *
     * A range needs a tab name, and the names are the user's to change, so ask rather than
     * assume "Sheet1" is still there.
     *
     * @return list<array{title: string|null, sheetId: int|null}>
     */
    public function listTabs(string $fileId): array
    {
        $this->drive->assertAccess($fileId);

        $spreadsheet = $this->sheets->spreadsheets->get($fileId, [
            'fields' => 'sheets.properties(title,sheetId)',
        ]);

        $tabs = [];

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();

            if ($properties === null) {
                continue;
            }

            $tabs[] = [
                'title'   => $properties->getTitle(),
                'sheetId' => $properties->getSheetId(),
            ];
        }

        return $tabs;
    }

    /**
     * The values of a range, as rows of columns.
     *
     * Google truncates: trailing empty cells are dropped from a row and trailing empty rows
     * from the range, so a request for A1:D3 can come back as a single two-element row. Rows
     * are padded here to the width of the widest one, so `$rows[2][3]` is always safe to read;
     * a padded cell is the empty string.
     *
     * Cells keep the type Google sends. Under RENDER_FORMATTED that is always a string; under
     * RENDER_RAW numbers arrive as int/float and checkboxes as bool, and dates as the serial
     * day number Sheets stores (45658.5 is 2025-01-01 12:00) — format them yourself or read
     * them formatted.
     *
     * @return list<list<string|int|float|bool>>
     */
    public function read(string $fileId, string $range, string $render = self::RENDER_FORMATTED): array
    {
        $this->assertRender($render);
        $this->drive->assertAccess($fileId);

        $response = $this->sheets->spreadsheets_values->get($fileId, $range, [
            'valueRenderOption' => $render,
        ]);

        return $this->pad($response->getValues());
    }

    /**
     * Several ranges in one request, keyed by the range that was asked for.
     *
     * Filling or reading a template usually touches a few separate blocks; this keeps that
     * to one round trip instead of one per block.
     *
     * @param string[] $ranges
     * @return array<string, list<list<string|int|float|bool>>>
     */
    public function readMany(string $fileId, array $ranges, string $render = self::RENDER_FORMATTED): array
    {
        $this->assertRender($render);
        $this->drive->assertAccess($fileId);

        if ($ranges === []) {
            return [];
        }

        $this->assertBatchSize(count($ranges));

        $response = $this->sheets->spreadsheets_values->batchGet($fileId, [
            'ranges'            => array_values($ranges),
            'valueRenderOption' => $render,
        ]);

        $result = [];
        $index  = 0;

        foreach ($response->getValueRanges() as $valueRange) {
            // Google echoes a normalised range ("Q3!A1:B1"); key by what the caller asked for
            // where possible so the result can be looked up without guessing the normal form.
            $key = array_values($ranges)[$index] ?? $valueRange->getRange() ?? (string) $index;

            $result[$key] = $this->pad($valueRange->getValues());
            ++$index;
        }

        return $result;
    }

    /**
     * Overwrite a range. The range anchors the top-left cell; the rows decide how far it extends.
     *
     * @param array<array<int, mixed>> $rows
     */
    public function write(string $fileId, string $range, array $rows, string $input = self::INPUT_RAW): void
    {
        $this->assertInput($input);
        $this->drive->assertAccess($fileId);

        $response = $this->sheets->spreadsheets_values->update($fileId, $range, new ValueRange([
            'range'  => $range,
            'values' => $rows,
        ]), [
            'valueInputOption' => $input,
        ]);

        $this->dispatch(new SheetValuesUpdatedEvent($fileId, $range, (int) $response->getUpdatedRows()));
    }

    /**
     * Overwrite several ranges in one request, keyed by range.
     *
     * @param array<string, array<array<int, mixed>>> $rowsByRange
     */
    public function writeMany(string $fileId, array $rowsByRange, string $input = self::INPUT_RAW): void
    {
        $this->assertInput($input);
        $this->drive->assertAccess($fileId);

        if ($rowsByRange === []) {
            return;
        }

        $this->assertBatchSize(count($rowsByRange));

        $data   = [];
        $ranges = [];

        foreach ($rowsByRange as $range => $rows) {
            $ranges[] = (string) $range;
            $data[]   = new ValueRange(['range' => (string) $range, 'values' => $rows]);
        }

        $request = new BatchUpdateValuesRequest();
        $request->setValueInputOption($input);
        $request->setData($data);

        $response = $this->sheets->spreadsheets_values->batchUpdate($fileId, $request);

        // Google answers block by block in the order sent; report its count like write() does.
        $responses = $response->getResponses() ?? [];

        foreach ($ranges as $index => $range) {
            $this->dispatch(new SheetValuesUpdatedEvent(
                $fileId,
                $range,
                (int) (($responses[$index] ?? null)?->getUpdatedRows() ?? 0)
            ));
        }
    }

    /**
     * Add rows after the last used row of a tab, leaving whatever is already there alone.
     *
     * Pass the tab name to append to a specific one; without it Google appends to the first.
     *
     * Not idempotent: should Google write the rows and then answer with a 5xx, the retry
     * policy (see README, "Retries") appends them a second time. Where a duplicate row is
     * unacceptable — an audit log, a ledger — write() into a computed range instead, or
     * run with `retry.attempts: 0` and handle the failure yourself.
     *
     * @param array<array<int, mixed>> $rows
     */
    public function append(string $fileId, array $rows, ?string $tab = null, string $input = self::INPUT_RAW): void
    {
        $this->assertInput($input);
        $this->drive->assertAccess($fileId);

        // A bare "A1" means "the first tab, find its end"; a tab name means that tab.
        $range = $tab !== null ? self::range($tab) : 'A1';

        $response = $this->sheets->spreadsheets_values->append($fileId, $range, new ValueRange([
            'values' => $rows,
        ]), [
            'valueInputOption' => $input,
            // Push existing rows down instead of overwriting whatever follows the block.
            'insertDataOption' => 'INSERT_ROWS',
        ]);

        // Where the block actually landed ("'Q3'!A10:B12"), falling back to what was asked for.
        $landed = $response->getUpdates()?->getUpdatedRange();

        $this->dispatch(new SheetRowsAppendedEvent($fileId, $landed ?: $range, count($rows)));
    }

    /**
     * Empty the values of a range. Formatting stays, and so do formulas pointing at it —
     * they simply see empty cells.
     */
    public function clear(string $fileId, string $range): void
    {
        $this->drive->assertAccess($fileId);

        $this->sheets->spreadsheets_values->clear($fileId, $range, new ClearValuesRequest());

        $this->dispatch(new SheetRangeClearedEvent($fileId, $range));
    }

    /**
     * A1 notation for a tab, quoted when the name needs it.
     *
     * Anything but letters, digits and underscores — a space, an apostrophe, a leading digit
     * — has to be quoted, and an apostrophe inside the name has to be doubled. So does a name
     * that reads like a cell: unquoted, "Q3" is the cell Q3 of the first tab, not the tab
     * called Q3, and Google silently uses the wrong one. Getting this wrong is a runtime
     * error at best and a write to the wrong place at worst.
     */
    public static function range(string $tab, ?string $cells = null): string
    {
        $plain = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tab) === 1
            && preg_match('/^[A-Za-z]+[0-9]+$/', $tab) !== 1;

        $quoted = $plain ? $tab : "'" . str_replace("'", "''", $tab) . "'";

        return $cells === null || $cells === '' ? $quoted : $quoted . '!' . $cells;
    }

    /**
     * Rows padded to the width of the widest one, with missing cells as empty strings.
     * Scalars pass through untouched — turning false into "" would make an unticked
     * checkbox indistinguishable from a cell that was never filled.
     *
     * @param mixed $values
     * @return list<list<string|int|float|bool>>
     */
    private function pad(mixed $values): array
    {
        if (!is_array($values) || $values === []) {
            return [];
        }

        /** @var list<array<int, mixed>> $rows */
        $rows  = array_values($values);
        $width = 0;

        foreach ($rows as $row) {
            $width = max($width, is_array($row) ? count($row) : 0);
        }

        $padded = [];

        foreach ($rows as $row) {
            $cells = is_array($row) ? array_values($row) : [];

            $padded[] = array_map(
                static fn (mixed $cell): string|int|float|bool => is_scalar($cell) ? $cell : '',
                array_pad($cells, $width, '')
            );
        }

        return $padded;
    }

    private function assertBatchSize(int $ranges): void
    {
        if ($ranges > self::MAX_BATCH_RANGES) {
            throw new \InvalidArgumentException(sprintf(
                'This bundle caps a batch call at %d ranges, %d given. Split the call.',
                self::MAX_BATCH_RANGES,
                $ranges
            ));
        }
    }

    private function assertInput(string $input): void
    {
        if (!in_array($input, self::INPUT_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported input mode "%s". Allowed: %s.',
                $input,
                implode(', ', self::INPUT_MODES)
            ));
        }
    }

    private function assertRender(string $render): void
    {
        if (!in_array($render, self::RENDER_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported render mode "%s". Allowed: %s.',
                $render,
                implode(', ', self::RENDER_MODES)
            ));
        }
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
