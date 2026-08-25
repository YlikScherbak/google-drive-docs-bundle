<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\DocumentImportedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\UploadTooLargeException;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceImportTest extends TestCase
{
    private const DRIVE_ID    = 'SHARED_DRIVE_ID';
    private const SPREADSHEET = 'application/vnd.google-apps.spreadsheet';
    private const DOCUMENT    = 'application/vnd.google-apps.document';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private CollectingEventDispatcher $dispatcher;

    /** @var string[] */
    private array $tempDirs = [];

    private ?DriveFile $metadata = null;
    /** @var array<string, mixed> */
    private array $params = [];

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->dispatcher  = new CollectingEventDispatcher();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->tempDirs = [];
    }

    public function testImportUploadsTheBytesAndConvertsToAGoogleSheet(): void
    {
        $path = $this->tempFile('prices.xlsx', 'xlsx-bytes');
        $this->captureCreate();

        $document = $this->service()->import($path, null, 'folder-9');

        self::assertSame(self::SPREADSHEET, $this->metadata->getMimeType());
        self::assertSame(['folder-9'], $this->metadata->getParents());
        self::assertSame('xlsx-bytes', $this->params['data']);
        self::assertSame(DriveExport::XLSX, $this->params['mimeType']);
        self::assertSame('multipart', $this->params['uploadType']);
        self::assertTrue($this->params['supportsAllDrives']);
        self::assertSame('doc-1', $document->id);
    }

    public function testImportDropsTheExtensionWhenTheFileIsConverted(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->captureCreate();

        $this->service()->import($path);

        // A Google Sheet named "prices.xlsx" would be nonsense: the extension is gone.
        self::assertSame('prices', $this->metadata->getName());
    }

    public function testImportKeepsTheWholeFilenameWhenNothingIsConverted(): void
    {
        $path = $this->tempFile('scan.pdf', 'pdf-bytes');
        $this->captureCreate();

        $this->service()->import($path);

        self::assertSame('scan.pdf', $this->metadata->getName());
        self::assertSame(DriveExport::PDF, $this->metadata->getMimeType());
        self::assertSame(DriveExport::PDF, $this->params['mimeType']);
    }

    public function testImportHonoursAnExplicitTitle(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->captureCreate();

        $this->service()->import($path, 'Q3 price list');

        self::assertSame('Q3 price list', $this->metadata->getName());
    }

    public function testImportCanStoreTheOriginalFileWithoutConverting(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->captureCreate();

        $this->service()->import($path, null, null, false);

        self::assertSame(DriveExport::XLSX, $this->metadata->getMimeType());
        self::assertSame('prices.xlsx', $this->metadata->getName());
    }

    /**
     * @dataProvider conversionTargets
     */
    public function testImportPicksTheRightGoogleTypePerFormat(string $filename, ?string $expected): void
    {
        $path = $this->tempFile($filename, 'x');
        $this->captureCreate();

        $this->service()->import($path);

        self::assertSame($expected, $this->metadata->getMimeType());
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function conversionTargets(): iterable
    {
        yield 'xlsx becomes a sheet'      => ['book.xlsx', self::SPREADSHEET];
        yield 'csv becomes a sheet'       => ['rows.csv', self::SPREADSHEET];
        yield 'ods becomes a sheet'       => ['book.ods', self::SPREADSHEET];
        yield 'docx becomes a doc'        => ['letter.docx', self::DOCUMENT];
        yield 'txt becomes a doc'         => ['notes.txt', self::DOCUMENT];
        yield 'pdf stays a pdf'           => ['scan.pdf', DriveExport::PDF];
        yield 'unknown stays unconverted' => ['archive.bin', 'application/octet-stream'];
    }

    public function testImportLetsTheCallerOverrideTheSourceType(): void
    {
        $path = $this->tempFile('rows.dat', 'a,b,c');
        $this->captureCreate();

        $this->service()->import($path, null, null, true, DriveExport::CSV);

        self::assertSame(self::SPREADSHEET, $this->metadata->getMimeType());
        self::assertSame(DriveExport::CSV, $this->params['mimeType']);
    }

    public function testImportDispatchesAnEventNamingTheUploadedFile(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->files->method('create')->willReturn($this->googleFile('doc-1', 'prices'));

        $this->service()->import($path, null, 'folder-9');

        $event = $this->dispatcher->single(DocumentImportedEvent::class);
        self::assertSame('doc-1', $event->fileId);
        self::assertSame('prices.xlsx', $event->originalFilename);
        self::assertSame('folder-9', $event->parentId);
    }

    public function testImportRequiresAccessToTheTargetFolder(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->files->method('get')->willReturn($this->googleFile('folder-9', 'Folder', [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
        $this->files->expects(self::never())->method('create');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->import($path, null, 'folder-9');
    }

    public function testImportRefusesFilesOverGooglesMultipartLimit(): void
    {
        // One byte past Google's 5 MB multipart ceiling.
        $path = $this->tempFile('huge.xlsx', str_repeat('x', DriveDocumentService::MAX_UPLOAD_BYTES + 1));
        $this->files->expects(self::never())->method('create');

        $this->expectException(UploadTooLargeException::class);

        $this->service()->import($path);
    }

    public function testImportRejectsAMissingFile(): void
    {
        $this->files->expects(self::never())->method('create');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->import(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.xlsx');
    }

    public function testImportLandsInTheDriveRootWhenNoFolderIsGiven(): void
    {
        $path = $this->tempFile('prices.xlsx', 'x');
        $this->captureCreate();

        $this->service()->import($path);

        self::assertSame([self::DRIVE_ID], $this->metadata->getParents());
    }

    /** Records the metadata and parameters the bundle sends to files.create. */
    private function captureCreate(): void
    {
        $this->files->method('create')->willReturnCallback(
            function (DriveFile $file, array $params): DriveFile {
                $this->metadata = $file;
                $this->params   = $params;

                return $this->googleFile('doc-1', $file->getName() ?? 'imported');
            }
        );
    }

    /** A file whose basename is exactly $name, so filename handling can be asserted. */
    private function tempFile(string $name, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/gddb-' . uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;

        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
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
            'mimeType'     => self::SPREADSHEET,
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
