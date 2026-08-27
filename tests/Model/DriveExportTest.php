<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Model;

use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * The header that decides what the browser calls the file it saves.
 *
 * It had no test at all, which is how an unescaped backslash in a file name went unnoticed: it
 * escaped the closing quote and the header arrived unterminated.
 */
final class DriveExportTest extends TestCase
{
    public function testAnOrdinaryNameGetsBothForms(): void
    {
        // The legacy parameter for old clients and the RFC 5987 one for everything since.
        $header = $this->export('Q3 report.csv')->contentDisposition();

        self::assertSame(
            'attachment; filename="Q3 report.csv"; filename*=UTF-8\'\'Q3%20report.csv',
            $header
        );
    }

    public function testTheDispositionCanBeInline(): void
    {
        self::assertStringStartsWith('inline; ', $this->export('a.csv')->contentDisposition('inline'));
    }

    public function testABackslashCannotEscapeTheClosingQuote(): void
    {
        // 'trail\' used to produce filename="trail\", where the backslash escapes the quote and
        // the parameter never ends.
        $header = $this->export('trail\\.csv')->contentDisposition();

        self::assertStringContainsString('filename="trail.csv"', $header);
        self::assertStringNotContainsString('\\"', $header);
    }

    public function testAQuoteInTheNameIsRemovedToo(): void
    {
        $header = $this->export('Bob"s file.csv')->contentDisposition();

        self::assertStringContainsString('filename="Bobs file.csv"', $header);
    }

    public function testNewlinesCannotSplitTheHeader(): void
    {
        // The one that would be an injection rather than a nuisance.
        $header = $this->export("a\r\nX-Evil: 1.csv")->contentDisposition();

        self::assertStringNotContainsString("\r", $header);
        self::assertStringNotContainsString("\n", $header);
    }

    public function testANonAsciiNameSurvivesInTheEncodedForm(): void
    {
        // The legacy parameter cannot carry it, which is exactly what the second one is for.
        $header = $this->export('Звіт.csv')->contentDisposition();

        self::assertStringContainsString('filename*=UTF-8\'\'' . rawurlencode('Звіт.csv'), $header);
    }

    public function testTheContentsCanBeReadBack(): void
    {
        self::assertSame('a,b', $this->export('a.csv', 'a,b')->contents());
    }

    public function testTheExtensionForAKnownTypeIsReported(): void
    {
        self::assertSame('csv', DriveExport::extensionFor(DriveExport::CSV));
        self::assertNull(DriveExport::extensionFor('application/x-invented'));
    }

    private function export(string $filename, string $body = ''): DriveExport
    {
        return new DriveExport($filename, DriveExport::CSV, Utils::streamFor($body));
    }
}
