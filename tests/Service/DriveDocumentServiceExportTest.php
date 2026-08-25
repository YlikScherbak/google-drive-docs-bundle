<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceExportTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private CollectingEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->dispatcher  = new CollectingEventDispatcher();
    }

    public function testExportAsksGoogleToConvertAndStreamsTheResult(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Q3 report'));

        $captured = null;
        $this->files->method('export')->willReturnCallback(
            function (string $id, string $mimeType, array $params) use (&$captured): Response {
                $captured = [$id, $mimeType, $params];

                return new Response(200, [], 'binary-xlsx-bytes');
            }
        );

        $export = $this->service()->export('doc-1', DriveExport::XLSX);

        self::assertSame(['doc-1', DriveExport::XLSX, ['alt' => 'media']], $captured);
        self::assertSame(DriveExport::XLSX, $export->mimeType);
        self::assertSame('binary-xlsx-bytes', $export->contents());
    }

    public function testExportNamesTheFileAfterTheDocumentAndFormat(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Q3 report'));
        $this->files->method('export')->willReturn(new Response(200, [], 'x'));

        self::assertSame('Q3 report.xlsx', $this->service()->export('doc-1', DriveExport::XLSX)->filename);
        self::assertSame('Q3 report.pdf', $this->service()->export('doc-1', DriveExport::PDF)->filename);
        self::assertSame('Q3 report.csv', $this->service()->export('doc-1', DriveExport::CSV)->filename);
    }

    public function testExportKeepsAnExtensionTheNameAlreadyHas(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Report.pdf'));
        $this->files->method('export')->willReturn(new Response(200, [], 'x'));

        self::assertSame('Report.pdf', $this->service()->export('doc-1', DriveExport::PDF)->filename);
    }

    public function testExportFallsBackToTheFileIdWhenTheNameIsMissing(): void
    {
        $this->files->method('get')->willReturn(new DriveFile([
            'id'       => 'doc-1',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ]));
        $this->files->method('export')->willReturn(new Response(200, [], 'x'));

        self::assertSame('doc-1.pdf', $this->service()->export('doc-1', DriveExport::PDF)->filename);
    }

    public function testExportOfAPlainFileReturnsTheStoredBytesUnchanged(): void
    {
        // Nothing to convert: an uploaded PDF is downloaded as it is, the requested format ignored.
        $this->files->method('get')->willReturnCallback(
            function (string $id, array $params): DriveFile|Response {
                return ($params['alt'] ?? null) === 'media'
                    ? new Response(200, [], 'raw-pdf-bytes')
                    : new DriveFile([
                        'id'       => 'file-1',
                        'name'     => 'Scan.pdf',
                        'mimeType' => 'application/pdf',
                    ]);
            }
        );
        $this->files->expects(self::never())->method('export');

        $export = $this->service()->export('file-1', DriveExport::XLSX);

        self::assertSame('Scan.pdf', $export->filename);
        self::assertSame('application/pdf', $export->mimeType);
        self::assertSame('raw-pdf-bytes', $export->contents());
    }

    public function testExportRequiresAccessToTheDocument(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Q3', parents: [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
        $this->files->expects(self::never())->method('export');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->export('doc-1', DriveExport::PDF);
    }

    public function testExportDispatchesNothing(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Q3 report'));
        $this->files->method('export')->willReturn(new Response(200, [], 'x'));

        $this->service()->export('doc-1', DriveExport::PDF);

        self::assertSame([], $this->dispatcher->events);
    }

    public function testExportStreamIsNotBufferedIntoTheModel(): void
    {
        $this->files->method('get')->willReturn($this->googleFile('doc-1', 'Q3 report'));
        $this->files->method('export')->willReturn(new Response(200, [], 'streamed'));

        $stream = $this->service()->export('doc-1', DriveExport::PDF)->stream;

        self::assertSame('streamed', $stream->getContents());
    }

    public function testKnownFormatsMapToFileExtensions(): void
    {
        self::assertSame('xlsx', DriveExport::extensionFor(DriveExport::XLSX));
        self::assertSame('pdf', DriveExport::extensionFor(DriveExport::PDF));
        self::assertSame('csv', DriveExport::extensionFor(DriveExport::CSV));
        self::assertSame('docx', DriveExport::extensionFor(DriveExport::DOCX));
        self::assertNull(DriveExport::extensionFor('application/x-made-up'));
    }

    private function service(?ViewerContextInterface $context = null): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            $this->dispatcher
        );
    }

    /**
     * @param string[] $parents
     */
    private function googleFile(string $id, string $name, array $parents = []): DriveFile
    {
        return new DriveFile([
            'id'           => $id,
            'name'         => $name,
            'mimeType'     => 'application/vnd.google-apps.spreadsheet',
            'webViewLink'  => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
            'modifiedTime' => '2026-01-01T00:00:00.000Z',
            'parents'      => $parents,
        ]);
    }

    /**
     * @param string[] $emails
     */
    private function permissionList(array $emails): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions(array_map(
            static fn (string $email): GooglePermission => new GooglePermission([
                'emailAddress' => $email,
                'type'         => 'user',
            ]),
            $emails
        ));

        return $list;
    }
}
