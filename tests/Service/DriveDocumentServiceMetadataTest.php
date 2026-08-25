<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Model\DriveCapabilities;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileCapabilities;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Drive\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceMetadataTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testGetExposesTheRicherMetadata(): void
    {
        $this->files->method('get')->willReturn($this->richFile());

        $document = $this->service()->get('doc-1');

        self::assertSame('2026-01-01T00:00:00.000Z', $document->createdTime);
        self::assertSame(4096, $document->size);
        self::assertSame('https://drive-thirdparty.googleusercontent.com/16/type/sheet', $document->iconLink);
        self::assertSame('https://lh3.googleusercontent.com/thumb', $document->thumbnailLink);
        self::assertSame('Ada Lovelace', $document->lastModifiedBy);
    }

    public function testCapabilitiesArriveOnListings(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->richFile()]));

        $items = $this->service()->listFolder();

        self::assertInstanceOf(DriveCapabilities::class, $items[0]->capabilities);
        self::assertTrue($items[0]->capabilities->canEdit);
        self::assertTrue($items[0]->capabilities->canRename);
        self::assertTrue($items[0]->capabilities->canShare);
        self::assertTrue($items[0]->capabilities->canTrash);
        self::assertFalse($items[0]->capabilities->canDelete);
        self::assertFalse($items[0]->capabilities->canUntrash);
    }

    public function testTheRequestedFieldsCoverTheNewMetadata(): void
    {
        $captured = null;
        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params['fields'];

                return $this->fileList([]);
            }
        );

        $this->service()->listFolder();

        foreach (['createdTime', 'size', 'iconLink', 'thumbnailLink', 'lastModifyingUser', 'capabilities('] as $field) {
            self::assertStringContainsString($field, $captured);
        }

        // Only the capabilities the bundle exposes are requested, not all thirty of them.
        self::assertStringNotContainsString('capabilities,', $captured);
    }

    public function testMetadataIsOptionalAndDefaultsAreSafe(): void
    {
        // Google omits most of this for a file it has nothing to say about.
        $this->files->method('get')->willReturn(new DriveFile([
            'id'       => 'doc-1',
            'name'     => 'Bare',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ]));

        $document = $this->service()->get('doc-1');

        self::assertNull($document->createdTime);
        self::assertNull($document->size);
        self::assertNull($document->iconLink);
        self::assertNull($document->thumbnailLink);
        self::assertNull($document->lastModifiedBy);
        self::assertNull($document->capabilities);
    }

    public function testAGoogleDocumentReportsNoSize(): void
    {
        // Google's own formats do not consume drive storage and carry no size at all.
        $file = $this->richFile();
        $file->setSize(null);

        $this->files->method('get')->willReturn($file);

        self::assertNull($this->service()->get('doc-1')->size);
    }

    public function testCapabilitiesFallBackToTheUserEmailWhenNoNameIsGiven(): void
    {
        $file = $this->richFile();
        $file->setLastModifyingUser(new User(['emailAddress' => 'ada@example.com']));

        $this->files->method('get')->willReturn($file);

        self::assertSame('ada@example.com', $this->service()->get('doc-1')->lastModifiedBy);
    }

    public function testCapabilitiesSerialiseWithTheDocument(): void
    {
        $this->files->method('get')->willReturn($this->richFile());

        $array = $this->service()->get('doc-1')->toArray();

        self::assertSame(4096, $array['size']);
        self::assertSame('Ada Lovelace', $array['lastModifiedBy']);
        self::assertIsArray($array['capabilities']);
        self::assertTrue($array['capabilities']['canEdit']);
        self::assertFalse($array['capabilities']['canDelete']);
    }

    public function testCapabilitiesAreNullInTheSerialisedFormWhenAbsent(): void
    {
        $document = new DriveDocument('a', 'A', null, null, null, DriveDocument::TYPE_DOCUMENT);

        self::assertNull($document->toArray()['capabilities']);
    }

    public function testMissingCapabilityFlagsCountAsNotAllowed(): void
    {
        // A partial capabilities object must never read as "allowed".
        $capabilities = new DriveCapabilities(canEdit: true);

        self::assertTrue($capabilities->canEdit);
        self::assertFalse($capabilities->canDelete);
        self::assertFalse($capabilities->canShare);
        self::assertFalse($capabilities->canAddChildren);
    }

    public function testGetReportsATrashedFolderSoTheCallerCanRefuseToOpenIt(): void
    {
        // Google marks only the folder it trashed, never its contents, so listFolder()
        // on a trashed folder id still lists what is inside. A caller that renders
        // breadcrumbs already fetches the folder — this is where it finds out.
        $folder = new DriveFile([
            'id'       => 'folder-1',
            'name'     => 'Old reports',
            'mimeType' => DriveDocumentService::FOLDER_MIME,
            'trashed'  => true,
        ]);

        $this->files->method('get')->willReturn($folder);

        $document = $this->service()->get('folder-1');

        self::assertTrue($document->isFolder());
        self::assertTrue($document->trashed);
    }

    private function richFile(): DriveFile
    {
        $file = new DriveFile([
            'id'            => 'doc-1',
            'name'          => 'Q3 report',
            'mimeType'      => 'application/vnd.google-apps.spreadsheet',
            'webViewLink'   => 'https://docs.google.com/spreadsheets/d/doc-1/edit',
            'modifiedTime'  => '2026-02-02T00:00:00.000Z',
            'createdTime'   => '2026-01-01T00:00:00.000Z',
            'size'          => '4096',
            'iconLink'      => 'https://drive-thirdparty.googleusercontent.com/16/type/sheet',
            'thumbnailLink' => 'https://lh3.googleusercontent.com/thumb',
        ]);

        $file->setLastModifyingUser(new User([
            'displayName'  => 'Ada Lovelace',
            'emailAddress' => 'ada@example.com',
        ]));

        $file->setCapabilities(new DriveFileCapabilities([
            'canEdit'                => true,
            'canRename'              => true,
            'canShare'               => true,
            'canTrash'               => true,
            'canCopy'                => true,
            'canDownload'            => true,
            'canAddChildren'         => false,
            'canMoveItemWithinDrive' => true,
            'canDelete'              => false,
            'canUntrash'             => false,
        ]));

        return $file;
    }

    private function service(): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false
        );
    }

    /**
     * @param DriveFile[] $files
     */
    private function fileList(array $files): FileList
    {
        $list = new FileList();
        $list->setFiles($files);

        return $list;
    }
}
