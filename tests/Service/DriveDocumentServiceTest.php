<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testListFolderMapsFoldersAndDocuments(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('folder-1', 'Portugal', DriveDocumentService::FOLDER_MIME),
            $this->file('doc-1', 'Price list', 'application/vnd.google-apps.spreadsheet'),
        ]));

        $items = $this->service()->listFolder();

        self::assertCount(2, $items);
        self::assertSame(DriveDocument::TYPE_FOLDER, $items[0]->type);
        self::assertTrue($items[0]->isFolder());
        self::assertSame('Portugal', $items[0]->name);
        self::assertSame(DriveDocument::TYPE_DOCUMENT, $items[1]->type);
        self::assertFalse($items[1]->isFolder());
    }

    public function testListFolderScopesQueryToTheRootOfTheSharedDrive(): void
    {
        $captured = null;

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params;

                return $this->fileList([]);
            }
        );

        $this->service()->listFolder();

        self::assertStringContainsString(sprintf("'%s' in parents", self::DRIVE_ID), $captured['q']);
        self::assertStringContainsString('trashed=false', $captured['q']);
        self::assertSame(self::DRIVE_ID, $captured['driveId']);
        self::assertTrue($captured['supportsAllDrives']);
    }

    public function testListFolderScopesQueryToTheGivenFolder(): void
    {
        $captured = null;

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params;

                return $this->fileList([]);
            }
        );

        $this->service()->listFolder('folder-42');

        self::assertStringContainsString("'folder-42' in parents", $captured['q']);
    }

    public function testListFolderHidesItemsNotSharedWithTheViewer(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('mine', 'Mine'),
            $this->file('foreign', 'Someone else'),
        ]));

        // Shared drives omit "permissions" from files.list, so the service falls back
        // to permissions.list for every item.
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $fileId): PermissionList => $this->permissionList(
                $fileId === 'mine' ? ['viewer@example.com'] : ['other@example.com']
            )
        );

        $items = $this->service(new FakeViewerContext('viewer@example.com'))->listFolder();

        self::assertCount(1, $items);
        self::assertSame('mine', $items[0]->id);
    }

    public function testListFolderReturnsNothingWhenTheViewerHasNoGoogleAccount(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        self::assertSame([], $this->service(new FakeViewerContext(null))->listFolder());
    }

    public function testAdministratorsBypassFiltering(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('foreign', 'Someone else'),
        ]));
        $this->permissions->expects(self::never())->method('listPermissions');

        $items = $this->service(new FakeViewerContext('admin@example.com', true))->listFolder();

        self::assertCount(1, $items);
    }

    public function testEnteringAForeignFolderIsDenied(): void
    {
        $this->files->method('get')->willReturn($this->file('folder-1', 'Foreign', DriveDocumentService::FOLDER_MIME));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList(['other@example.com']));

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->listFolder('folder-1');
    }

    public function testEnteringAnAllowedFolderShowsItsWholeContent(): void
    {
        $this->files->method('get')->willReturn($this->file('folder-1', 'Mine', DriveDocumentService::FOLDER_MIME));
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('a', 'A'),
            $this->file('b', 'B'),
        ]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList(['viewer@example.com']));

        // Access is granted on the folder, so its children are not filtered one by one.
        $items = $this->service(new FakeViewerContext('viewer@example.com'))->listFolder('folder-1');

        self::assertCount(2, $items);
    }

    public function testCanAccessFollowsTheParentChain(): void
    {
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => match ($id) {
                'doc'    => $this->file('doc', 'Doc', 'application/vnd.google-apps.spreadsheet', ['folder']),
                'folder' => $this->file('folder', 'Folder', DriveDocumentService::FOLDER_MIME, [self::DRIVE_ID]),
                default  => throw new \LogicException('unexpected id ' . $id),
            }
        );

        // Nothing is shared on the document itself; the grant lives on the parent folder.
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $fileId): PermissionList => $this->permissionList(
                $fileId === 'folder' ? ['viewer@example.com'] : []
            )
        );

        self::assertTrue($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testCanAccessIsFalseWhenNothingInTheChainIsShared(): void
    {
        $this->files->method('get')->willReturn(
            $this->file('doc', 'Doc', 'application/vnd.google-apps.spreadsheet', [self::DRIVE_ID])
        );
        $this->permissions->method('listPermissions')->willReturn($this->permissionList(['other@example.com']));

        self::assertFalse($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testCanAccessCollapsesGmailAliases(): void
    {
        $this->files->method('get')->willReturn(
            $this->file('doc', 'Doc', 'application/vnd.google-apps.spreadsheet', [self::DRIVE_ID])
        );
        $this->permissions->method('listPermissions')->willReturn($this->permissionList(['User+invoices@Gmail.com']));

        self::assertTrue($this->service(new FakeViewerContext('user@gmail.com'))->canAccess('doc'));
    }

    public function testCreateFolderUsesTheFolderMimeTypeAndTheDriveRoot(): void
    {
        $captured = null;

        $this->files->method('create')->willReturnCallback(
            function (DriveFile $file) use (&$captured): DriveFile {
                $captured = $file;

                return $this->file('new-folder', $file->getName(), DriveDocumentService::FOLDER_MIME);
            }
        );

        $folder = $this->service()->createFolder('Reports');

        self::assertSame(DriveDocumentService::FOLDER_MIME, $captured->getMimeType());
        self::assertSame([self::DRIVE_ID], $captured->getParents());
        self::assertTrue($folder->isFolder());
    }

    public function testCreateDocumentFallsBackToTheFirstConfiguredMimeType(): void
    {
        $captured = null;

        $this->files->method('create')->willReturnCallback(
            function (DriveFile $file) use (&$captured): DriveFile {
                $captured = $file;

                return $this->file('new-doc', $file->getName());
            }
        );

        $this->service()->createDocument('Q3', 'folder-1');

        self::assertSame('application/vnd.google-apps.spreadsheet', $captured->getMimeType());
        self::assertSame(['folder-1'], $captured->getParents());
    }

    public function testMoveDetachesTheItemFromItsPreviousParent(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, ['old-parent']));

        $captured = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file, array $params) use (&$captured): DriveFile {
                $captured = $params;

                return $this->file('doc', 'Doc');
            }
        );

        $this->service()->move('doc', 'new-parent');

        self::assertSame('new-parent', $captured['addParents']);
        self::assertSame('old-parent', $captured['removeParents']);
    }

    public function testMoveWithoutTargetSendsTheItemToTheDriveRoot(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, ['old-parent']));

        $captured = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file, array $params) use (&$captured): DriveFile {
                $captured = $params;

                return $this->file('doc', 'Doc');
            }
        );

        $this->service()->move('doc', null);

        self::assertSame(self::DRIVE_ID, $captured['addParents']);
    }

    public function testGrantRejectsAnUnsupportedRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->grant('doc', 'user@example.com', 'owner');
    }

    public function testGrantKeepsTheRequestedRoleWhenGoogleOmitsIt(): void
    {
        // Google may answer without role/type for alias addresses.
        $this->permissions->method('create')->willReturn(new GooglePermission(['id' => 'perm-1']));

        $permission = $this->service()->grant('doc', 'user@example.com', 'reader');

        self::assertSame('perm-1', $permission->id);
        self::assertSame('reader', $permission->role);
        self::assertSame('user@example.com', $permission->emailAddress);
        self::assertSame('user', $permission->type);
    }

    public function testRevokeTranslatesInheritedPermissionErrors(): void
    {
        $this->permissions->method('delete')->willThrowException(
            new GoogleServiceException('The authenticated user cannot delete the permission. If the permission is inherited...', 403)
        );

        $this->expectException(InheritedPermissionException::class);

        $this->service()->revoke('doc', 'perm-1');
    }

    public function testListPermissionsFlagsInheritedEntries(): void
    {
        $direct = new GooglePermission([
            'id'           => 'perm-direct',
            'emailAddress' => 'owner@example.com',
            'role'         => 'writer',
            'type'         => 'user',
        ]);

        $inherited = new GooglePermission([
            'id'           => 'perm-inherited',
            'emailAddress' => 'team@example.com',
            'role'         => 'writer',
            'type'         => 'user',
        ]);
        $inherited->setPermissionDetails([
            new Drive\PermissionPermissionDetails(['inherited' => true, 'inheritedFrom' => 'folder-1']),
        ]);

        $list = new PermissionList();
        $list->setPermissions([$direct, $inherited]);
        $this->permissions->method('listPermissions')->willReturn($list);

        $permissions = $this->service()->listPermissions('doc');

        self::assertFalse($permissions[0]->inherited);
        self::assertNull($permissions[0]->inheritedFrom);
        self::assertTrue($permissions[1]->inherited);
        self::assertSame('folder-1', $permissions[1]->inheritedFrom);
    }

    public function testSearchIgnoresBlankQueries(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        self::assertSame([], $this->service()->search('   '));
    }

    public function testSearchEscapesQuotesInTheQuery(): void
    {
        $captured = null;

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params;

                return $this->fileList([]);
            }
        );

        $this->service()->search("O'Brien");

        self::assertStringContainsString("name contains 'O\\'Brien'", $captured['q']);
    }

    public function testUnconfiguredDriveIsReported(): void
    {
        $this->expectException(NotConfiguredException::class);

        $this->service(null, '')->listFolder();
    }

    public function testAssertAccessRejectsForeignItems(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->assertAccess('doc');
    }

    private function service(?ViewerContextInterface $context = null, string $driveId = self::DRIVE_ID): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            $driveId,
            ['application/vnd.google-apps.spreadsheet'],
            false
        );
    }

    /**
     * @param string[] $parents
     */
    private function file(
        string $id,
        string $name,
        ?string $mimeType = 'application/vnd.google-apps.spreadsheet',
        array $parents = []
    ): DriveFile {
        return new DriveFile([
            'id'           => $id,
            'name'         => $name,
            'mimeType'     => $mimeType,
            'webViewLink'  => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
            'modifiedTime' => '2026-01-01T00:00:00.000Z',
            'parents'      => $parents,
        ]);
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
                'role'         => 'writer',
            ]),
            $emails
        ));

        return $list;
    }
}
