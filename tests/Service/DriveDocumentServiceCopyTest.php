<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\DocumentCopiedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\NotCopyableException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceCopyTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private CollectingEventDispatcher $dispatcher;
    private ?DriveFile $copyPayload = null;
    /** @var array<string, mixed> */
    private array $copyParams = [];

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->dispatcher  = new CollectingEventDispatcher();
    }

    public function testCopyLandsBesideTheOriginalWhenNoFolderIsGiven(): void
    {
        $this->captureCopy();

        $document = $this->service()->copy('doc-1');

        // No parents in the payload: Google then puts the copy where the original lives.
        self::assertNull($this->copyPayload->getParents());
        self::assertNull($this->copyPayload->getName());
        self::assertTrue($this->copyParams['supportsAllDrives']);
        self::assertSame('copy-1', $document->id);
    }

    public function testCopyAppliesTheRequestedTitleAndFolder(): void
    {
        $this->captureCopy();

        $this->service()->copy('doc-1', 'Q4 report', 'folder-9');

        self::assertSame('Q4 report', $this->copyPayload->getName());
        self::assertSame(['folder-9'], $this->copyPayload->getParents());
    }

    public function testCopyDispatchesAnEventNamingTheSource(): void
    {
        $this->files->method('copy')->willReturn($this->file('copy-1', 'Q3 report (copy)'));

        $this->service()->copy('doc-1', 'Q3 report (copy)', 'folder-9');

        $event = $this->dispatcher->single(DocumentCopiedEvent::class);
        self::assertSame('copy-1', $event->fileId);
        self::assertSame('doc-1', $event->sourceId);
        self::assertSame('folder-9', $event->parentId);
        self::assertSame('Q3 report (copy)', $event->document->name);
    }

    public function testCopyRequiresAccessToTheSource(): void
    {
        $this->denyAccess();
        $this->files->expects(self::never())->method('copy');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->copy('doc-1');
    }

    public function testCopyRequiresAccessToTheTargetFolder(): void
    {
        // The source is shared with the viewer, the destination folder is not.
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => $this->file($id, $id, parents: [self::DRIVE_ID])
        );
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $this->permissionList(
                $id === 'doc-1' ? ['viewer@example.com'] : []
            )
        );
        $this->files->expects(self::never())->method('copy');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->copy('doc-1', 'Copy', 'folder-9');
    }

    public function testCopyExplainsThatFoldersCannotBeCopied(): void
    {
        $this->files->method('copy')->willThrowException(
            new GoogleServiceException('fileNotCopyable: Files of this type cannot be copied.', 403)
        );

        $this->expectException(NotCopyableException::class);

        $this->service()->copy('folder-1');
    }

    public function testCopyLeavesOtherGoogleErrorsAlone(): void
    {
        $this->files->method('copy')->willThrowException(
            new GoogleServiceException('File not found: doc-1.', 404)
        );

        $this->expectException(GoogleServiceException::class);

        $this->service()->copy('doc-1');
    }

    public function testCreateFromTemplateCopiesUnderTheNewName(): void
    {
        $this->captureCopy();

        $document = $this->service()->createFromTemplate('template-1', 'Invoice #4711', 'folder-9');

        self::assertSame('Invoice #4711', $this->copyPayload->getName());
        self::assertSame(['folder-9'], $this->copyPayload->getParents());
        self::assertSame('copy-1', $document->id);
    }

    public function testCreateFromTemplateReportsTheTemplateAsTheSource(): void
    {
        $this->files->method('copy')->willReturn($this->file('copy-1', 'Invoice #4711'));

        $this->service()->createFromTemplate('template-1', 'Invoice #4711');

        self::assertSame('template-1', $this->dispatcher->single(DocumentCopiedEvent::class)->sourceId);
    }

    /** Records what the bundle sends to files.copy into $copyPayload / $copyParams. */
    private function captureCopy(): void
    {
        $this->files->method('copy')->willReturnCallback(
            function (string $id, DriveFile $file, array $options): DriveFile {
                $this->copyPayload = $file;
                $this->copyParams  = $options;

                return $this->file('copy-1', 'Copy');
            }
        );
    }

    private function denyAccess(): void
    {
        $this->files->method('get')->willReturn($this->file('doc-1', 'Doc', parents: [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
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
    private function file(string $id, string $name, array $parents = []): DriveFile
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
