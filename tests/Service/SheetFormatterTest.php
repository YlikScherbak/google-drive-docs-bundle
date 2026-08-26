<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Event\SheetFormattedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetTabAddedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Sheets;
use Google\Service\Sheets\AddSheetResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\Resource\Spreadsheets;
use Google\Service\Sheets\Resource\SpreadsheetsValues;
use Google\Service\Sheets\Response as SheetsResponse;
use Google\Service\Sheets\Sheet;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\Spreadsheet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SheetFormatterTest extends TestCase
{
    private Spreadsheets&MockObject $spreadsheets;
    private SpreadsheetsValues&MockObject $values;
    private DriveDocumentService&MockObject $drive;
    private CollectingEventDispatcher $dispatcher;

    private ?BatchUpdateSpreadsheetRequest $sent = null;

    protected function setUp(): void
    {
        $this->spreadsheets = $this->createMock(Spreadsheets::class);
        $this->values       = $this->createMock(SpreadsheetsValues::class);
        $this->drive        = $this->createMock(DriveDocumentService::class);
        $this->dispatcher   = new CollectingEventDispatcher();

        $this->tabs(['Q3' => 0, 'Summary' => 42]);
    }

    public function testTheWholePassTravelsInOneRequest(): void
    {
        $this->captureBatchUpdate();
        $this->spreadsheets->expects(self::once())->method('batchUpdate');

        $this->service()->format('sheet-1')
            ->style('Q3!A1:D1', bold: true)
            ->numberFormat('Q3!D2:D', '#,##0.00')
            ->freeze('Q3', rows: 1)
            ->apply();

        self::assertCount(3, $this->sent->getRequests());
    }

    public function testTabNamesBecomeSheetIds(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')
            ->style('Summary!A1', bold: true)
            ->apply();

        self::assertSame(42, $this->sent->getRequests()[0]->getRepeatCell()->getRange()->getSheetId());
    }

    public function testARangeWithoutATabUsesTheFirstOne(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->style('A1:B2', bold: true)->apply();

        self::assertSame(0, $this->sent->getRequests()[0]->getRepeatCell()->getRange()->getSheetId());
    }

    public function testAnUnknownTabIsRefusedBeforeCallingGoogle(): void
    {
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Ghost/');

        $this->service()->format('sheet-1')->style('Ghost!A1', bold: true)->apply();
    }

    public function testStyleWritesOnlyTheFieldsItWasGiven(): void
    {
        // repeatCell replaces whatever the mask covers, so the mask must name exactly what
        // was asked for — a wider one would wipe formatting nobody touched.
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->style('Q3!A1:D1', bold: true)->apply();

        $fields = $this->sent->getRequests()[0]->getRepeatCell()->getFields();

        self::assertSame('userEnteredFormat.textFormat.bold', $fields);
    }

    public function testStyleCombinesSeveralAttributesIntoOneMask(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')
            ->style('Q3!A1:D1', bold: true, background: '#DDE6EC', horizontalAlign: 'CENTER')
            ->apply();

        $cell   = $this->sent->getRequests()[0]->getRepeatCell();
        $fields = explode(',', $cell->getFields());

        self::assertContains('userEnteredFormat.textFormat.bold', $fields);
        self::assertContains('userEnteredFormat.backgroundColor', $fields);
        self::assertContains('userEnteredFormat.horizontalAlignment', $fields);
        self::assertTrue($cell->getCell()->getUserEnteredFormat()->getTextFormat()->getBold());
        self::assertSame('CENTER', $cell->getCell()->getUserEnteredFormat()->getHorizontalAlignment());
    }

    public function testStyleTranslatesAHexColourToGooglesFloats(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->style('Q3!A1', background: '#FF8000')->apply();

        $colour = $this->sent->getRequests()[0]->getRepeatCell()->getCell()->getUserEnteredFormat()->getBackgroundColor();

        self::assertSame(1.0, $colour->getRed());
        self::assertEqualsWithDelta(0.502, $colour->getGreen(), 0.001);
        self::assertSame(0.0, $colour->getBlue());
    }

    public function testStyleAcceptsAShorthandColour(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->style('Q3!A1', background: '#F00')->apply();

        $colour = $this->sent->getRequests()[0]->getRepeatCell()->getCell()->getUserEnteredFormat()->getBackgroundColor();

        self::assertSame(1.0, $colour->getRed());
        self::assertSame(0.0, $colour->getGreen());
    }

    /**
     * @dataProvider badColours
     */
    public function testStyleRefusesAColourItCannotRead(string $colour): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->format('sheet-1')->style('Q3!A1', background: $colour);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badColours(): iterable
    {
        yield 'no hash'        => ['FF8000'];
        yield 'too short'      => ['#FF'];
        yield 'not hex'        => ['#GGGGGG'];
        yield 'a colour name'  => ['red'];
    }

    public function testStyleRefusesAnUnknownAlignment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->format('sheet-1')->style('Q3!A1', horizontalAlign: 'MIDDLE');
    }

    public function testStyleWithNothingToChangeIsIgnored(): void
    {
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->service()->format('sheet-1')->style('Q3!A1')->apply();
    }

    public function testNumberFormatCarriesThePattern(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->numberFormat('Q3!D2:D', '#,##0.00 ₴')->apply();

        $cell = $this->sent->getRequests()[0]->getRepeatCell();

        self::assertSame('userEnteredFormat.numberFormat', $cell->getFields());
        self::assertSame('#,##0.00 ₴', $cell->getCell()->getUserEnteredFormat()->getNumberFormat()->getPattern());
    }

    public function testFreezeSetsTheRowAndColumnCounts(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->freeze('Q3', rows: 1, columns: 2)->apply();

        $update = $this->sent->getRequests()[0]->getUpdateSheetProperties();

        self::assertSame(0, $update->getProperties()->getSheetId());
        self::assertSame(1, $update->getProperties()->getGridProperties()->getFrozenRowCount());
        self::assertSame(2, $update->getProperties()->getGridProperties()->getFrozenColumnCount());
        self::assertStringContainsString('frozenRowCount', $update->getFields());
    }

    public function testFreezeOnlyMentionsWhatItWasAsked(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->freeze('Q3', rows: 1)->apply();

        $fields = $this->sent->getRequests()[0]->getUpdateSheetProperties()->getFields();

        self::assertStringContainsString('frozenRowCount', $fields);
        self::assertStringNotContainsString('frozenColumnCount', $fields);
    }

    public function testAutoResizeColumnsCoversTheWholeTab(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->autoResizeColumns('Q3')->apply();

        $dimensions = $this->sent->getRequests()[0]->getAutoResizeDimensions()->getDimensions();

        self::assertSame(0, $dimensions->getSheetId());
        self::assertSame('COLUMNS', $dimensions->getDimension());
    }

    public function testColumnWidthIsSetInPixels(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->columnWidth('Q3!A:B', 240)->apply();

        $update = $this->sent->getRequests()[0]->getUpdateDimensionProperties();

        self::assertSame('COLUMNS', $update->getRange()->getDimension());
        self::assertSame(0, $update->getRange()->getStartIndex());
        self::assertSame(2, $update->getRange()->getEndIndex());
        self::assertSame(240, $update->getProperties()->getPixelSize());
    }

    public function testColumnWidthRefusesAnAbsurdSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->format('sheet-1')->columnWidth('Q3!A:B', 0);
    }

    public function testMergeAndUnmergeTargetTheSameRange(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->merge('Q3!A1:D1')->unmerge('Q3!A5:D5')->apply();

        self::assertSame(0, $this->sent->getRequests()[0]->getMergeCells()->getRange()->getStartRowIndex());
        self::assertSame(4, $this->sent->getRequests()[1]->getUnmergeCells()->getRange()->getStartRowIndex());
    }

    public function testApplyingNothingAsksGoogleNothing(): void
    {
        $this->spreadsheets->expects(self::never())->method('batchUpdate');
        $this->spreadsheets->expects(self::never())->method('get');

        $this->service()->format('sheet-1')->apply();

        self::assertSame([], $this->dispatcher->events);
    }

    public function testASpreadsheetGoogleDescribesWithoutTabsIsReportedClearly(): void
    {
        // Not "no tab called ''": the tabs exist, the answer describing them did not arrive.
        // A fresh mock, because setUp() already taught the shared one about two tabs.
        $this->spreadsheets = $this->createMock(Spreadsheets::class);
        $this->spreadsheets->method('get')->willReturn(new Spreadsheet());
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(UnexpectedDriveStateException::class);
        $this->expectExceptionMessageMatches('/without a single usable tab/');

        $this->service()->format('sheet-1')->freeze('Q3', rows: 1)->apply();
    }

    public function testApplyingTwiceDoesNotResendThePass(): void
    {
        $this->captureBatchUpdate();
        $this->spreadsheets->expects(self::once())->method('batchUpdate');

        $formatter = $this->service()->format('sheet-1')->style('Q3!A1', bold: true);
        $formatter->apply();
        $formatter->apply();
    }

    public function testApplyRequiresAccessToTheSpreadsheet(): void
    {
        $this->drive->method('assertAccess')->willThrowException(new AccessDeniedException('nope'));
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(AccessDeniedException::class);

        $this->service()->format('sheet-1')->style('Q3!A1', bold: true)->apply();
    }

    public function testApplyReportsWhatItDid(): void
    {
        $this->captureBatchUpdate();

        $this->service()->format('sheet-1')->style('Q3!A1', bold: true)->merge('Q3!A1:B1')->apply();

        $event = $this->dispatcher->single(SheetFormattedEvent::class);
        self::assertSame('sheet-1', $event->fileId);
        self::assertSame(2, $event->operations);
    }

    public function testAddTabReturnsTheNewSheetIdAndReportsIt(): void
    {
        $reply = new SheetsResponse();
        $reply->setAddSheet(new AddSheetResponse([
            'properties' => new SheetProperties(['title' => 'Notes', 'sheetId' => 77]),
        ]));

        $response = new BatchUpdateSpreadsheetResponse();
        $response->setReplies([$reply]);
        $this->spreadsheets->method('batchUpdate')->willReturn($response);

        $sheetId = $this->service()->addTab('sheet-1', 'Notes');

        self::assertSame(77, $sheetId);

        $event = $this->dispatcher->single(SheetTabAddedEvent::class);
        self::assertSame('Notes', $event->title);
        self::assertSame(77, $event->sheetId);
    }

    public function testAddTabRequiresAccess(): void
    {
        $this->drive->method('assertAccess')->willThrowException(new AccessDeniedException('nope'));
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(AccessDeniedException::class);

        $this->service()->addTab('sheet-1', 'Notes');
    }

    /**
     * @param array<string, int> $titles
     */
    private function tabs(array $titles): void
    {
        $sheets = [];

        foreach ($titles as $title => $id) {
            $sheets[] = new Sheet(['properties' => new SheetProperties(['title' => $title, 'sheetId' => $id])]);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->setSheets($sheets);

        $this->spreadsheets->method('get')->willReturn($spreadsheet);
    }

    private function captureBatchUpdate(): void
    {
        $this->spreadsheets->method('batchUpdate')->willReturnCallback(
            function (string $id, BatchUpdateSpreadsheetRequest $request): BatchUpdateSpreadsheetResponse {
                $this->sent = $request;

                return new BatchUpdateSpreadsheetResponse();
            }
        );
    }

    private function service(): SpreadsheetService
    {
        $sheets                      = $this->createMock(Sheets::class);
        $sheets->spreadsheets        = $this->spreadsheets;
        $sheets->spreadsheets_values = $this->values;

        return new SpreadsheetService($sheets, $this->drive, $this->dispatcher);
    }
}
