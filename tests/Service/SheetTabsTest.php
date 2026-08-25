<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Event\SheetTabDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\SheetTabRenamedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\Resource\Spreadsheets;
use Google\Service\Sheets\Resource\SpreadsheetsValues;
use Google\Service\Sheets\Sheet;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\Spreadsheet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SheetTabsTest extends TestCase
{
    private Spreadsheets&MockObject $spreadsheets;
    private DriveDocumentService&MockObject $drive;
    private CollectingEventDispatcher $dispatcher;
    private ?BatchUpdateSpreadsheetRequest $sent = null;

    protected function setUp(): void
    {
        $this->spreadsheets = $this->createMock(Spreadsheets::class);
        $this->drive        = $this->createMock(DriveDocumentService::class);
        $this->dispatcher   = new CollectingEventDispatcher();

        $this->spreadsheets->method('batchUpdate')->willReturnCallback(
            function (string $id, BatchUpdateSpreadsheetRequest $request): BatchUpdateSpreadsheetResponse {
                $this->sent = $request;

                return new BatchUpdateSpreadsheetResponse();
            }
        );
    }

    public function testRenameTabChangesOnlyTheTitle(): void
    {
        $this->tabs(['Q3' => 0, 'Notes' => 7]);

        $this->service()->renameTab('sheet-1', 'Q3', 'Q4');

        $update = $this->sent->getRequests()[0]->getUpdateSheetProperties();

        self::assertSame(0, $update->getProperties()->getSheetId());
        self::assertSame('Q4', $update->getProperties()->getTitle());
        self::assertSame('title', $update->getFields());

        $event = $this->dispatcher->single(SheetTabRenamedEvent::class);
        self::assertSame('Q3', $event->from);
        self::assertSame('Q4', $event->to);
    }

    public function testRenameTabRefusesAnUnknownTab(): void
    {
        $this->tabs(['Q3' => 0]);
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Ghost/');

        $this->service()->renameTab('sheet-1', 'Ghost', 'Q4');
    }

    public function testRenameTabRefusesANameAlreadyTaken(): void
    {
        // Google rejects duplicate titles; saying so up front beats a 400 from the API.
        $this->tabs(['Q3' => 0, 'Q4' => 1]);
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already/');

        $this->service()->renameTab('sheet-1', 'Q3', 'Q4');
    }

    public function testDeleteTabRemovesItAndReportsIt(): void
    {
        $this->tabs(['Q3' => 0, 'Notes' => 7]);

        $this->service()->deleteTab('sheet-1', 'Notes');

        self::assertSame(7, $this->sent->getRequests()[0]->getDeleteSheet()->getSheetId());

        $event = $this->dispatcher->single(SheetTabDeletedEvent::class);
        self::assertSame('Notes', $event->title);
        self::assertSame(7, $event->sheetId);
    }

    public function testDeleteTabRefusesTheLastOne(): void
    {
        // A spreadsheet must keep at least one tab. Google errors; this explains why.
        $this->tabs(['Q3' => 0]);
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only tab/');

        $this->service()->deleteTab('sheet-1', 'Q3');
    }

    public function testDeleteTabRefusesAnUnknownTab(): void
    {
        $this->tabs(['Q3' => 0, 'Notes' => 7]);
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->deleteTab('sheet-1', 'Ghost');
    }

    /**
     * @dataProvider guarded
     */
    public function testTabOperationsRequireAccess(callable $call): void
    {
        $this->tabs(['Q3' => 0, 'Notes' => 7]);
        $this->drive->method('assertAccess')->willThrowException(new AccessDeniedException('nope'));
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->expectException(AccessDeniedException::class);

        $call($this->service());
    }

    /**
     * @return iterable<string, array{0: callable}>
     */
    public static function guarded(): iterable
    {
        yield 'renameTab' => [static fn (SpreadsheetService $s) => $s->renameTab('sheet-1', 'Q3', 'Q4')];
        yield 'deleteTab' => [static fn (SpreadsheetService $s) => $s->deleteTab('sheet-1', 'Notes')];
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

    private function service(): SpreadsheetService
    {
        $sheets                      = $this->createMock(Sheets::class);
        $sheets->spreadsheets        = $this->spreadsheets;
        $sheets->spreadsheets_values = $this->createMock(SpreadsheetsValues::class);

        return new SpreadsheetService($sheets, $this->drive, $this->dispatcher);
    }
}
