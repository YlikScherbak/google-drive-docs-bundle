<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SheetFormatter;
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

final class SheetFormatterExtrasTest extends TestCase
{
    private Spreadsheets&MockObject $spreadsheets;
    private DriveDocumentService&MockObject $drive;
    private ?BatchUpdateSpreadsheetRequest $sent = null;

    protected function setUp(): void
    {
        $this->spreadsheets = $this->createMock(Spreadsheets::class);
        $this->drive        = $this->createMock(DriveDocumentService::class);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->setSheets([
            new Sheet(['properties' => new SheetProperties(['title' => 'Q3', 'sheetId' => 0])]),
        ]);
        $this->spreadsheets->method('get')->willReturn($spreadsheet);

        $this->spreadsheets->method('batchUpdate')->willReturnCallback(
            function (string $id, BatchUpdateSpreadsheetRequest $request): BatchUpdateSpreadsheetResponse {
                $this->sent = $request;

                return new BatchUpdateSpreadsheetResponse();
            }
        );
    }

    // -------------------------------------------------------------- borders

    public function testBordersOutlineTheBlockByDefault(): void
    {
        $this->format()->borders('Q3!A1:D5')->apply();

        $borders = $this->sent->getRequests()[0]->getUpdateBorders();

        self::assertSame(0, $borders->getRange()->getStartRowIndex());
        self::assertSame('SOLID', $borders->getTop()->getStyle());
        self::assertNotNull($borders->getBottom());
        self::assertNotNull($borders->getLeft());
        self::assertNotNull($borders->getRight());
        self::assertNull($borders->getInnerHorizontal());
    }

    public function testBordersCanDrawTheInnerGridToo(): void
    {
        $this->format()->borders('Q3!A1:D5', inner: true, style: 'DASHED', color: '#888888')->apply();

        $borders = $this->sent->getRequests()[0]->getUpdateBorders();

        self::assertSame('DASHED', $borders->getInnerHorizontal()->getStyle());
        self::assertSame('DASHED', $borders->getInnerVertical()->getStyle());
        self::assertEqualsWithDelta(0.533, $borders->getTop()->getColorStyle()->getRgbColor()->getRed(), 0.001);
    }

    public function testBordersCanDrawOnlyTheInnerGrid(): void
    {
        $this->format()->borders('Q3!A1:D5', outline: false, inner: true)->apply();

        $borders = $this->sent->getRequests()[0]->getUpdateBorders();

        self::assertNull($borders->getTop());
        self::assertNotNull($borders->getInnerVertical());
    }

    public function testBordersWithNothingToDrawIsIgnored(): void
    {
        $this->spreadsheets->expects(self::never())->method('batchUpdate');

        $this->format()->borders('Q3!A1:D5', outline: false, inner: false)->apply();
    }

    // ---------------------------------------------------------- row height

    public function testRowHeightIsSetInPixels(): void
    {
        $this->format()->rowHeight('Q3!1:1', 48)->apply();

        $update = $this->sent->getRequests()[0]->getUpdateDimensionProperties();

        self::assertSame('ROWS', $update->getRange()->getDimension());
        self::assertSame(0, $update->getRange()->getStartIndex());
        self::assertSame(1, $update->getRange()->getEndIndex());
        self::assertSame(48, $update->getProperties()->getPixelSize());
    }

    // ------------------------------------------------ conditional formatting

    public function testConditionalFormatCarriesTheRuleAndTheFormat(): void
    {
        $this->format()
            ->conditionalFormat('Q3!D2:D', 'NUMBER_LESS', ['0'], background: '#FFD5D5', bold: true)
            ->apply();

        $rule = $this->sent->getRequests()[0]->getAddConditionalFormatRule()->getRule();
        self::assertNotNull($rule->getBooleanRule()->getFormat()->getBackgroundColorStyle());

        self::assertSame('NUMBER_LESS', $rule->getBooleanRule()->getCondition()->getType());
        self::assertSame('0', $rule->getBooleanRule()->getCondition()->getValues()[0]->getUserEnteredValue());
        self::assertTrue($rule->getBooleanRule()->getFormat()->getTextFormat()->getBold());
        self::assertSame(3, $rule->getRanges()[0]->getStartColumnIndex());
    }

    public function testConditionalFormatTakesACustomFormula(): void
    {
        $this->format()
            ->conditionalFormat('Q3!A2:D', 'CUSTOM_FORMULA', ['=$D2<0'], background: '#FFD5D5')
            ->apply();

        $condition = $this->sent->getRequests()[0]->getAddConditionalFormatRule()->getRule()
            ->getBooleanRule()->getCondition();

        self::assertSame('CUSTOM_FORMULA', $condition->getType());
        self::assertSame('=$D2<0', $condition->getValues()[0]->getUserEnteredValue());
    }

    public function testConditionalFormatNeedsSomethingToApply(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->format()->conditionalFormat('Q3!D2:D', 'NUMBER_LESS', ['0']);
    }

    // ------------------------------------------------------ data validation

    public function testDataValidationBuildsADropdown(): void
    {
        $this->format()->dataValidation('Q3!C2:C', 'ONE_OF_LIST', ['New', 'Paid', 'Shipped'])->apply();

        $rule = $this->sent->getRequests()[0]->getSetDataValidation()->getRule();

        self::assertSame('ONE_OF_LIST', $rule->getCondition()->getType());
        self::assertCount(3, $rule->getCondition()->getValues());
        self::assertTrue($rule->getShowCustomUi());
        self::assertTrue($rule->getStrict());
    }

    public function testDataValidationCanBeAdvisoryRatherThanStrict(): void
    {
        $this->format()
            ->dataValidation('Q3!C2:C', 'NUMBER_GREATER', ['0'], strict: false, message: 'Positive only')
            ->apply();

        $rule = $this->sent->getRequests()[0]->getSetDataValidation()->getRule();

        self::assertFalse($rule->getStrict());
        self::assertSame('Positive only', $rule->getInputMessage());
    }

    // -------------------------------------------------------------- filters

    public function testBasicFilterCoversTheRange(): void
    {
        $this->format()->basicFilter('Q3!A1:D')->apply();

        self::assertSame(0, $this->sent->getRequests()[0]->getSetBasicFilter()->getFilter()->getRange()->getSheetId());
    }

    public function testClearBasicFilterTargetsTheTab(): void
    {
        $this->format()->clearBasicFilter('Q3')->apply();

        self::assertSame(0, $this->sent->getRequests()[0]->getClearBasicFilter()->getSheetId());
    }

    // ------------------------------------------------------------ protection

    public function testProtectLocksTheRange(): void
    {
        $this->format()->protect('Q3!D2:D', description: 'Formulas')->apply();

        $protected = $this->sent->getRequests()[0]->getAddProtectedRange()->getProtectedRange();

        self::assertSame('Formulas', $protected->getDescription());
        self::assertSame(3, $protected->getRange()->getStartColumnIndex());
    }

    public function testProtectCanNameWhoMayStillEdit(): void
    {
        $this->format()->protect('Q3!D2:D', editors: ['boss@example.com'])->apply();

        $protected = $this->sent->getRequests()[0]->getAddProtectedRange()->getProtectedRange();

        self::assertSame(['boss@example.com'], $protected->getEditors()->getUsers());
    }

    // ---------------------------------------------------------------- bands

    public function testBandedRowsAlternateTwoColours(): void
    {
        $this->format()->bandedRows('Q3!A2:D', second: '#F3F3F3')->apply();

        $banding = $this->sent->getRequests()[0]->getAddBanding()->getBandedRange();

        self::assertSame(1.0, $banding->getRowProperties()->getFirstBandColorStyle()->getRgbColor()->getRed());
        self::assertEqualsWithDelta(
            0.953,
            $banding->getRowProperties()->getSecondBandColorStyle()->getRgbColor()->getRed(),
            0.001
        );
    }

    // ----------------------------------------------------------- tab looks

    public function testHideAndShowTabFlipTheSameField(): void
    {
        $this->format()->hideTab('Q3')->apply();
        self::assertTrue($this->sent->getRequests()[0]->getUpdateSheetProperties()->getProperties()->getHidden());
        self::assertSame('hidden', $this->sent->getRequests()[0]->getUpdateSheetProperties()->getFields());

        $this->format()->showTab('Q3')->apply();
        self::assertFalse($this->sent->getRequests()[0]->getUpdateSheetProperties()->getProperties()->getHidden());
    }

    public function testTabColourIsSet(): void
    {
        $this->format()->tabColor('Q3', '#00FF00')->apply();

        $update = $this->sent->getRequests()[0]->getUpdateSheetProperties();

        self::assertSame(1.0, $update->getProperties()->getTabColorStyle()->getRgbColor()->getGreen());
        self::assertSame('tabColorStyle', $update->getFields());
    }

    public function testEverythingStillTravelsInOneRequest(): void
    {
        $this->spreadsheets->expects(self::once())->method('batchUpdate');

        $this->format()
            ->borders('Q3!A1:D5', inner: true)
            ->rowHeight('Q3!1:1', 40)
            ->conditionalFormat('Q3!D2:D', 'NUMBER_LESS', ['0'], background: '#FFD5D5')
            ->dataValidation('Q3!C2:C', 'ONE_OF_LIST', ['New', 'Paid'])
            ->basicFilter('Q3!A1:D')
            ->protect('Q3!D2:D')
            ->bandedRows('Q3!A2:D')
            ->hideTab('Q3')
            ->tabColor('Q3', '#123456')
            ->apply();

        self::assertCount(9, $this->sent->getRequests());
    }

    public function testAPassRefusesMoreOperationsThanOneBatchTakes(): void
    {
        // The whole pass travels as one batchUpdate; Google answers an oversized one with a
        // bare 400, so the call that went too far is named here instead.
        $formatter = $this->format();

        for ($i = 0; $i < SheetFormatter::MAX_OPERATIONS; ++$i) {
            $formatter->freeze('Q3', rows: 1);
        }

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessageMatches('/apply\(\)/');

        $formatter->freeze('Q3', rows: 1);
    }

    private function format(): \Borsche\GoogleDriveDocsBundle\Service\SheetFormatter
    {
        $sheets                      = $this->createMock(Sheets::class);
        $sheets->spreadsheets        = $this->spreadsheets;
        $sheets->spreadsheets_values = $this->createMock(SpreadsheetsValues::class);

        return (new SpreadsheetService($sheets, $this->drive, new CollectingEventDispatcher()))
            ->format('sheet-1');
    }
}
