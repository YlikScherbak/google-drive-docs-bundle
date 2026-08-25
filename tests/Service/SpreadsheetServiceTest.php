<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Event\SheetRangeClearedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetRowsAppendedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetValuesUpdatedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchGetValuesResponse;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\ClearValuesResponse;
use Google\Service\Sheets\Resource\Spreadsheets;
use Google\Service\Sheets\Resource\SpreadsheetsValues;
use Google\Service\Sheets\Sheet;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\UpdateValuesResponse;
use Google\Service\Sheets\ValueRange;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SpreadsheetServiceTest extends TestCase
{
    private Spreadsheets&MockObject $spreadsheets;
    private SpreadsheetsValues&MockObject $values;
    private DriveDocumentService&MockObject $drive;
    private CollectingEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->spreadsheets = $this->createMock(Spreadsheets::class);
        $this->values       = $this->createMock(SpreadsheetsValues::class);
        $this->drive        = $this->createMock(DriveDocumentService::class);
        $this->dispatcher   = new CollectingEventDispatcher();
    }

    // ---------------------------------------------------------------- reading

    public function testReadReturnsTheRows(): void
    {
        $this->values->method('get')->willReturn(new ValueRange([
            'values' => [['Item', 'Qty'], ['Bolt', '12']],
        ]));

        self::assertSame(
            [['Item', 'Qty'], ['Bolt', '12']],
            $this->service()->read('sheet-1', 'Q3!A1:B2')
        );
    }

    public function testReadPadsTheRaggedRowsGoogleReturns(): void
    {
        // Google drops trailing empty cells, so a 4-column range can come back ragged.
        $this->values->method('get')->willReturn(new ValueRange([
            'values' => [['a', 'b', 'c', 'd'], ['e'], []],
        ]));

        self::assertSame(
            [['a', 'b', 'c', 'd'], ['e', '', '', ''], ['', '', '', '']],
            $this->service()->read('sheet-1', 'Q3!A1:D3')
        );
    }

    public function testReadKeepsTheTypesGoogleSendsForUnformattedValues(): void
    {
        // UNFORMATTED_VALUE yields real numbers and booleans: a checkbox that is off must
        // stay false, not collapse into the empty string that also marks a missing cell.
        $this->values->method('get')->willReturn(new ValueRange([
            'values' => [['Qty', 'Paid', 'Total'], [12, false, 1234567.89], [3, true]],
        ]));

        self::assertSame(
            [['Qty', 'Paid', 'Total'], [12, false, 1234567.89], [3, true, '']],
            $this->service()->read('sheet-1', 'Orders!A1:C3', SpreadsheetService::RENDER_RAW)
        );
    }

    public function testReadOfAnEmptyRangeIsAnEmptyArray(): void
    {
        $this->values->method('get')->willReturn(new ValueRange());

        self::assertSame([], $this->service()->read('sheet-1', 'Q3!A1:D3'));
    }

    public function testReadAsksForFormattedValuesByDefault(): void
    {
        $captured = null;
        $this->values->method('get')->willReturnCallback(
            function (string $id, string $range, array $params) use (&$captured): ValueRange {
                $captured = $params;

                return new ValueRange();
            }
        );

        $this->service()->read('sheet-1', 'Q3!A1:B2');

        self::assertSame(SpreadsheetService::RENDER_FORMATTED, $captured['valueRenderOption']);
    }

    public function testReadCanAskForFormulasInstead(): void
    {
        $captured = null;
        $this->values->method('get')->willReturnCallback(
            function (string $id, string $range, array $params) use (&$captured): ValueRange {
                $captured = $params;

                return new ValueRange();
            }
        );

        $this->service()->read('sheet-1', 'Q3!A1:B2', SpreadsheetService::RENDER_FORMULA);

        self::assertSame('FORMULA', $captured['valueRenderOption']);
    }

    public function testReadRejectsAnUnknownRenderOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->read('sheet-1', 'Q3!A1', 'MADE_UP');
    }

    public function testReadManyKeepsTheRangesInTheOrderAsked(): void
    {
        $response = new BatchGetValuesResponse();
        $response->setValueRanges([
            new ValueRange(['range' => 'Q3!A1:B1', 'values' => [['head']]]),
            new ValueRange(['range' => 'Q3!A5:B5', 'values' => [['body']]]),
        ]);
        $this->values->method('batchGet')->willReturn($response);

        self::assertSame(
            ['Q3!A1:B1' => [['head']], 'Q3!A5:B5' => [['body']]],
            $this->service()->readMany('sheet-1', ['Q3!A1:B1', 'Q3!A5:B5'])
        );
    }

    public function testReadManyHandlesAResponseWithoutAnyValueRanges(): void
    {
        // Verified against the client: array-typed Google fields come back as [] rather than
        // null, so this cannot warn the way Exception::getErrors() once did. Pinned anyway,
        // because the empty-response path is the one no real spreadsheet exercises.
        $this->values->method('batchGet')->willReturn(new BatchGetValuesResponse());

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            self::assertSame([], $this->service()->readMany('sheet-1', ['Q3!A1:B1']));
        } finally {
            restore_error_handler();
        }
    }

    public function testReadManyOfNothingAsksGoogleNothing(): void
    {
        $this->values->expects(self::never())->method('batchGet');

        self::assertSame([], $this->service()->readMany('sheet-1', []));
    }

    public function testReadManyRefusesMoreRangesThanGoogleAccepts(): void
    {
        $this->values->expects(self::never())->method('batchGet');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->readMany('sheet-1', array_map(static fn (int $i): string => 'A' . $i, range(1, 101)));
    }

    public function testReadingDispatchesNothing(): void
    {
        $this->values->method('get')->willReturn(new ValueRange());

        $this->service()->read('sheet-1', 'Q3!A1');

        self::assertSame([], $this->dispatcher->events);
    }

    // ---------------------------------------------------------------- writing

    public function testWriteStoresValuesLiterallyByDefault(): void
    {
        $payload = null;
        $params  = null;
        $this->values->method('update')->willReturnCallback(
            function (string $id, string $range, ValueRange $body, array $options) use (&$payload, &$params): UpdateValuesResponse {
                $payload = $body;
                $params  = $options;

                return new UpdateValuesResponse();
            }
        );

        $this->service()->write('sheet-1', 'Q3!A2', [['=SUM(A1:A2)']]);

        // RAW: a string that looks like a formula stays a string.
        self::assertSame('RAW', $params['valueInputOption']);
        self::assertSame([['=SUM(A1:A2)']], $payload->getValues());
        self::assertSame('Q3!A2', $payload->getRange());
    }

    public function testWriteCanLetGoogleParseTheInput(): void
    {
        $params = null;
        $this->values->method('update')->willReturnCallback(
            function (string $id, string $range, ValueRange $body, array $options) use (&$params): UpdateValuesResponse {
                $params = $options;

                return new UpdateValuesResponse();
            }
        );

        $this->service()->write('sheet-1', 'Q3!A2', [['=SUM(A1:A2)']], SpreadsheetService::INPUT_AS_TYPED);

        self::assertSame('USER_ENTERED', $params['valueInputOption']);
    }

    public function testWriteRejectsAnUnknownInputMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->write('sheet-1', 'Q3!A2', [['x']], 'GUESS');
    }

    public function testWriteDispatchesAnEvent(): void
    {
        $this->values->method('update')->willReturn(new UpdateValuesResponse(['updatedRows' => 2]));

        $this->service()->write('sheet-1', 'Q3!A2', [['a'], ['b']]);

        $event = $this->dispatcher->single(SheetValuesUpdatedEvent::class);
        self::assertSame('sheet-1', $event->fileId);
        self::assertSame('Q3!A2', $event->range);
        self::assertSame(2, $event->rows);
    }

    public function testAppendAddsAfterTheLastRow(): void
    {
        $captured = null;
        $this->values->method('append')->willReturnCallback(
            function (string $id, string $range, ValueRange $body, array $options) use (&$captured) {
                $captured = [$range, $options];

                return new Sheets\AppendValuesResponse();
            }
        );

        $this->service()->append('sheet-1', [['a', 'b']], 'Q3');

        // "Q3" alone would be the cell Q3 of the first tab; quoted it is the tab named Q3.
        self::assertSame("'Q3'", $captured[0]);
        self::assertSame('RAW', $captured[1]['valueInputOption']);
        self::assertSame('INSERT_ROWS', $captured[1]['insertDataOption']);
    }

    public function testAppendWithoutATabLetsGooglePickTheFirstOne(): void
    {
        $captured = null;
        $this->values->method('append')->willReturnCallback(
            function (string $id, string $range) use (&$captured) {
                $captured = $range;

                return new Sheets\AppendValuesResponse();
            }
        );

        $this->service()->append('sheet-1', [['a']]);

        self::assertSame('A1', $captured);
    }

    public function testAppendDispatchesAnEventNamingWhereTheRowsLanded(): void
    {
        // Google tells where the block actually went; that is what a listener wants, not "Q3".
        $this->values->method('append')->willReturn(new Sheets\AppendValuesResponse([
            'updates' => new UpdateValuesResponse(['updatedRange' => "'Q3'!A10:A12", 'updatedRows' => 3]),
        ]));

        $this->service()->append('sheet-1', [['a'], ['b'], ['c']], 'Q3');

        $event = $this->dispatcher->single(SheetRowsAppendedEvent::class);
        self::assertSame('sheet-1', $event->fileId);
        self::assertSame("'Q3'!A10:A12", $event->range);
        self::assertSame(3, $event->rows);
    }

    public function testAppendFallsBackToTheRequestedRangeWhenGoogleIsSilent(): void
    {
        $this->values->method('append')->willReturn(new Sheets\AppendValuesResponse());

        $this->service()->append('sheet-1', [['a'], ['b']], 'Q3');

        $event = $this->dispatcher->single(SheetRowsAppendedEvent::class);
        self::assertSame("'Q3'", $event->range);
        self::assertSame(2, $event->rows);
    }

    public function testWriteManySendsOneRequestForEveryBlock(): void
    {
        $request = null;
        $this->values->expects(self::once())->method('batchUpdate')->willReturnCallback(
            function (string $id, BatchUpdateValuesRequest $body) use (&$request): BatchUpdateValuesResponse {
                $request = $body;

                return new BatchUpdateValuesResponse();
            }
        );

        $this->service()->writeMany('sheet-1', ['Q3!A1' => [['head']], 'Q3!A5' => [['body']]]);

        self::assertSame('RAW', $request->getValueInputOption());
        self::assertCount(2, $request->getData());
        self::assertSame('Q3!A1', $request->getData()[0]->getRange());
        self::assertSame([['body']], $request->getData()[1]->getValues());
    }

    public function testWriteManyReportsWhatGoogleChangedForEveryBlock(): void
    {
        // Same rule as write(): the event carries Google's count, 0 when nothing changed.
        $this->values->method('batchUpdate')->willReturn(new BatchUpdateValuesResponse([
            'responses' => [
                new UpdateValuesResponse(['updatedRange' => 'Report!A1:B1', 'updatedRows' => 1]),
                new UpdateValuesResponse(['updatedRange' => 'Report!A5:B7', 'updatedRows' => 0]),
            ],
        ]));

        $this->service()->writeMany('sheet-1', [
            'Report!A1' => [['head', 'er']],
            'Report!A5' => [['same'], ['as'], ['before']],
        ]);

        $events = $this->dispatcher->events;
        self::assertCount(2, $events);
        self::assertInstanceOf(SheetValuesUpdatedEvent::class, $events[0]);
        self::assertSame(['Report!A1', 1], [$events[0]->range, $events[0]->rows]);
        self::assertInstanceOf(SheetValuesUpdatedEvent::class, $events[1]);
        self::assertSame(['Report!A5', 0], [$events[1]->range, $events[1]->rows]);
    }

    public function testWriteManyRefusesMoreBlocksThanGoogleAccepts(): void
    {
        $this->values->expects(self::never())->method('batchUpdate');
        $blocks = [];

        for ($i = 1; $i <= 101; ++$i) {
            $blocks['Report!A' . $i] = [['x']];
        }

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->writeMany('sheet-1', $blocks);
    }

    public function testWriteManyIgnoresAnEmptySetInsteadOfCallingGoogle(): void
    {
        $this->values->expects(self::never())->method('batchUpdate');

        $this->service()->writeMany('sheet-1', []);

        self::assertSame([], $this->dispatcher->events);
    }

    public function testClearEmptiesTheRangeAndReportsIt(): void
    {
        $captured = null;
        $this->values->method('clear')->willReturnCallback(
            function (string $id, string $range, ClearValuesRequest $body) use (&$captured): ClearValuesResponse {
                $captured = $range;

                return new ClearValuesResponse();
            }
        );

        $this->service()->clear('sheet-1', 'Q3!A2:D');

        self::assertSame('Q3!A2:D', $captured);
        self::assertSame('Q3!A2:D', $this->dispatcher->single(SheetRangeClearedEvent::class)->range);
    }

    // ------------------------------------------------------------------- tabs

    public function testListTabsReportsTitleAndId(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->setSheets([
            new Sheet(['properties' => new SheetProperties(['title' => 'Q3', 'sheetId' => 0, 'index' => 0])]),
            new Sheet(['properties' => new SheetProperties(['title' => 'Notes', 'sheetId' => 42, 'index' => 1])]),
        ]);
        $this->spreadsheets->method('get')->willReturn($spreadsheet);

        self::assertSame(
            [['title' => 'Q3', 'sheetId' => 0], ['title' => 'Notes', 'sheetId' => 42]],
            $this->service()->listTabs('sheet-1')
        );
    }

    public function testListTabsSkipsASheetWithoutProperties(): void
    {
        $bare = new Sheet();
        $full = new Sheet();
        $full->setProperties(new SheetProperties(['title' => 'Orders', 'sheetId' => 7]));
        $this->spreadsheets->method('get')->willReturn(new Spreadsheet(['sheets' => [$bare, $full]]));

        self::assertSame([['title' => 'Orders', 'sheetId' => 7]], $this->service()->listTabs('sheet-1'));
    }

    public function testListTabsOfASpreadsheetWithoutSheetsIsEmpty(): void
    {
        $this->spreadsheets->method('get')->willReturn(new Spreadsheet());

        self::assertSame([], $this->service()->listTabs('sheet-1'));
    }

    public function testListTabsAsksOnlyForTheProperties(): void
    {
        $captured = null;
        $this->spreadsheets->method('get')->willReturnCallback(
            function (string $id, array $params) use (&$captured): Spreadsheet {
                $captured = $params;

                return new Spreadsheet();
            }
        );

        $this->service()->listTabs('sheet-1');

        self::assertSame('sheets.properties(title,sheetId)', $captured['fields']);
    }

    // ---------------------------------------------------------- authorisation

    /**
     * @dataProvider guardedCalls
     */
    public function testEveryOperationRequiresAccessToTheSpreadsheet(callable $call): void
    {
        $this->drive->method('assertAccess')->willThrowException(new AccessDeniedException('nope'));
        $this->values->expects(self::never())->method('get');
        $this->values->expects(self::never())->method('update');
        $this->values->expects(self::never())->method('append');
        $this->values->expects(self::never())->method('clear');
        $this->values->expects(self::never())->method('batchGet');
        $this->values->expects(self::never())->method('batchUpdate');
        $this->spreadsheets->expects(self::never())->method('get');

        $this->expectException(AccessDeniedException::class);

        $call($this->service());
    }

    /**
     * @return iterable<string, array{0: callable}>
     */
    public static function guardedCalls(): iterable
    {
        yield 'read'      => [static fn (SpreadsheetService $s) => $s->read('sheet-1', 'A1')];
        yield 'readMany'  => [static fn (SpreadsheetService $s) => $s->readMany('sheet-1', ['A1'])];
        yield 'write'     => [static fn (SpreadsheetService $s) => $s->write('sheet-1', 'A1', [['x']])];
        yield 'writeMany' => [static fn (SpreadsheetService $s) => $s->writeMany('sheet-1', ['A1' => [['x']]])];
        yield 'append'    => [static fn (SpreadsheetService $s) => $s->append('sheet-1', [['x']])];
        yield 'clear'     => [static fn (SpreadsheetService $s) => $s->clear('sheet-1', 'A1')];
        yield 'listTabs'  => [static fn (SpreadsheetService $s) => $s->listTabs('sheet-1')];
    }

    // ----------------------------------------------------------- range helper

    /**
     * @dataProvider ranges
     */
    public function testRangeQuotesTabNamesThatNeedIt(string $tab, ?string $cells, string $expected): void
    {
        self::assertSame($expected, SpreadsheetService::range($tab, $cells));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function ranges(): iterable
    {
        yield 'a plain name needs nothing' => ['Orders', 'A1:C10', 'Orders!A1:C10'];
        yield 'a space forces quotes'      => ['My Sheet', 'A1', "'My Sheet'!A1"];
        yield 'an apostrophe is doubled'   => ["Bob's", 'A1', "'Bob''s'!A1"];
        yield 'a whole tab has no cells'   => ['Orders', null, 'Orders'];
        yield 'a quoted whole tab'         => ['My Sheet', null, "'My Sheet'"];
        yield 'a digit start needs quotes' => ['2026', 'A1', "'2026'!A1"];
        // Unquoted, Google reads these as the cell Q3 / A1 / ZZ999 of the first tab.
        yield 'a cell-like name is quoted' => ['Q3', 'A1:C10', "'Q3'!A1:C10"];
        yield 'a cell-like whole tab too'  => ['Q3', null, "'Q3'"];
        yield 'a wide column counts'       => ['ZZ999', null, "'ZZ999'"];
        yield 'a column-only name is fine' => ['ABC', null, 'ABC'];
    }

    private function service(): SpreadsheetService
    {
        $sheets                      = $this->createMock(Sheets::class);
        $sheets->spreadsheets        = $this->spreadsheets;
        $sheets->spreadsheets_values = $this->values;

        return new SpreadsheetService($sheets, $this->drive, $this->dispatcher);
    }
}
