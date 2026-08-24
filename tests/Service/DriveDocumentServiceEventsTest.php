<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Event\AccessGrantedEvent;
use Borsche\GoogleDriveDocsBundle\Event\AccessRevokedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentCreatedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentMovedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRenamedEvent;
use Borsche\GoogleDriveDocsBundle\Event\FolderCreatedEvent;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceEventsTest extends TestCase
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

    public function testCreatingADocumentDispatchesAnEvent(): void
    {
        $this->files->method('create')->willReturn($this->file('doc-1', 'Q3'));

        $this->service()->createDocument('Q3', 'folder-1');

        $event = $this->dispatcher->single(DocumentCreatedEvent::class);
        self::assertSame('doc-1', $event->fileId);
        self::assertSame('Q3', $event->document->name);
        self::assertSame('folder-1', $event->parentId);
    }

    public function testCreatingAFolderDispatchesItsOwnEvent(): void
    {
        $this->files->method('create')->willReturn(
            $this->file('folder-9', 'Portugal', DriveDocumentService::FOLDER_MIME)
        );

        $this->service()->createFolder('Portugal');

        $event = $this->dispatcher->single(FolderCreatedEvent::class);
        self::assertSame('folder-9', $event->fileId);
        self::assertTrue($event->folder->isFolder());
        self::assertNull($event->parentId);
    }

    public function testRenamingDispatchesAnEvent(): void
    {
        $this->files->method('update')->willReturn($this->file('doc-1', 'New name'));

        $this->service()->rename('doc-1', 'New name');

        $event = $this->dispatcher->single(DocumentRenamedEvent::class);
        self::assertSame('New name', $event->document->name);
    }

    public function testMovingReportsBothEnds(): void
    {
        $this->files->method('get')->willReturn($this->file('doc-1', 'Doc', null, ['old-parent']));
        $this->files->method('update')->willReturn($this->file('doc-1', 'Doc'));

        $this->service()->move('doc-1', 'new-parent');

        $event = $this->dispatcher->single(DocumentMovedEvent::class);
        self::assertSame('old-parent', $event->fromParentId);
        self::assertSame('new-parent', $event->toParentId);
    }

    public function testDeletingDispatchesAnEvent(): void
    {
        $this->service()->delete('doc-1');

        self::assertSame('doc-1', $this->dispatcher->single(DocumentDeletedEvent::class)->fileId);
    }

    public function testGrantingAccessDispatchesAnEvent(): void
    {
        $this->permissions->method('create')->willReturn(new GooglePermission([
            'id'           => 'perm-1',
            'emailAddress' => 'user@example.com',
            'role'         => 'reader',
            'type'         => 'user',
        ]));

        $this->service()->grant('doc-1', 'user@example.com', 'reader');

        $event = $this->dispatcher->single(AccessGrantedEvent::class);
        self::assertSame('doc-1', $event->fileId);
        self::assertSame('user@example.com', $event->permission->emailAddress);
        self::assertSame('reader', $event->permission->role);
    }

    public function testRevokingAccessDispatchesAnEvent(): void
    {
        $this->service()->revoke('doc-1', 'perm-1');

        $event = $this->dispatcher->single(AccessRevokedEvent::class);
        self::assertSame('doc-1', $event->fileId);
        self::assertSame('perm-1', $event->permissionId);
    }

    public function testReadOperationsDispatchNothing(): void
    {
        $this->files->method('get')->willReturn($this->file('doc-1', 'Doc'));

        $this->service()->get('doc-1');

        self::assertSame([], $this->dispatcher->events);
    }

    public function testTheServiceWorksWithoutADispatcher(): void
    {
        $this->files->method('create')->willReturn($this->file('doc-1', 'Q3'));

        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        $service = new DriveDocumentService(
            $drive,
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false
        );

        self::assertSame('doc-1', $service->createDocument('Q3')->id);
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
            false,
            $this->dispatcher
        );
    }

    /**
     * @param string[] $parents
     */
    private function file(string $id, string $name, ?string $mimeType = null, array $parents = []): DriveFile
    {
        return new DriveFile([
            'id'       => $id,
            'name'     => $name,
            'mimeType' => $mimeType ?? 'application/vnd.google-apps.spreadsheet',
            'parents'  => $parents,
        ]);
    }
}
