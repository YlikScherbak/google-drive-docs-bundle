<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
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
            new GoogleServiceException(
                'The authenticated user cannot delete the permission. If the permission is inherited...',
                403,
                null,
                [['reason' => 'cannotModifyInheritedPermission']]
            )
        );

        $this->expectException(InheritedPermissionException::class);

        $this->service()->revoke('doc', 'perm-1');
    }

    public function testRevokeSurvivesAGoogleErrorCarryingNoMachineReadableReasons(): void
    {
        $this->permissions->method('delete')->willThrowException(
            new GoogleServiceException('Forbidden', 403, null, null)
        );

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->service()->revoke('doc', 'perm-1');
            self::fail('Expected the Google error to surface.');
        } catch (GoogleServiceException $e) {
            self::assertSame(403, $e->getCode());
        } finally {
            restore_error_handler();
        }
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

    public function testGrantOnAForeignItemIsDenied(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
        $this->permissions->expects(self::never())->method('create');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->grant('doc', 'viewer@example.com', 'writer');
    }

    public function testGrantAsServiceReachesAnItemTheViewerCannotSeeYet(): void
    {
        // The "creator gets access" case: the service user just created the document in the
        // drive root, so nobody is on it yet and the viewer check would refuse the very grant
        // meant to give the creator access.
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
        $this->permissions->expects(self::once())->method('create')->willReturn(
            new GooglePermission([
                'id'           => 'perm-1',
                'emailAddress' => 'viewer@example.com',
                'role'         => 'writer',
                'type'         => 'user',
            ])
        );

        $permission = $this->service(new FakeViewerContext('viewer@example.com'))
            ->grantAsService('doc', 'viewer@example.com', 'writer');

        self::assertSame('perm-1', $permission->id);
        self::assertSame('writer', $permission->role);
    }

    public function testGrantAsServiceNeverAsksWhoTheViewerIs(): void
    {
        // No access walk at all: the application is acting, not the viewer.
        $this->files->expects(self::never())->method('get');
        $this->permissions->method('create')->willReturn(new GooglePermission(['id' => 'perm-1']));

        $this->service(new FakeViewerContext('viewer@example.com'))
            ->grantAsService('doc', 'someone@example.com', 'reader');
    }

    public function testGrantAsServiceStillValidatesTheRole(): void
    {
        $this->permissions->expects(self::never())->method('create');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->grantAsService('doc', 'user@example.com', 'owner');
    }

    public function testGrantAsServiceCanShareWithAGroup(): void
    {
        $captured = null;
        $this->permissions->method('create')->willReturnCallback(
            function (string $id, GooglePermission $permission) use (&$captured): GooglePermission {
                $captured = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $this->service()->grantAsService('doc', 'team@example.com', 'writer', 'group');

        self::assertSame('group', $captured->getType());
    }

    public function testRevokeOnAForeignItemIsDenied(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
        $this->permissions->expects(self::never())->method('delete');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->revoke('doc', 'perm-1');
    }

    public function testListPermissionsOfAForeignItemIsDenied(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, [self::DRIVE_ID]));
        // The access check itself asks for the direct grants; nothing else may be listed.
        $this->permissions->expects(self::once())->method('listPermissions')->willReturn($this->permissionList([]));

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->listPermissions('doc');
    }

    public function testCanAccessTreatsAnUnknownItemAsNotShared(): void
    {
        $this->files->method('get')->willThrowException(new GoogleServiceException('File not found', 404));

        self::assertFalse($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testCanAccessDoesNotHideGoogleOutagesBehindADenial(): void
    {
        // A 5xx after the retries are spent is an outage, not a missing grant.
        $this->files->method('get')->willThrowException(new GoogleServiceException('Backend Error', 503));

        $this->expectException(GoogleServiceException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc');
    }

    public function testCanAccessNeedsAConfiguredDriveBeforeWalkingTheTree(): void
    {
        $this->files->expects(self::never())->method('get');

        $this->expectException(NotConfiguredException::class);

        $this->service(new FakeViewerContext('viewer@example.com'), '')->canAccess('doc');
    }

    public function testMoveRefusesWhenGoogleDoesNotReportTheCurrentParent(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', null, []));
        $this->files->expects(self::never())->method('update');

        $this->expectException(UnexpectedDriveStateException::class);

        $this->service()->move('doc', 'new-parent');
    }

    public function testListFolderRejectsAMalformedFolderId(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->listFolder("x' or trashed=false or '");
    }

    public function testCanAccessGivesUpOnAnEndlessParentChain(): void
    {
        // Every item points to yet another parent and the drive root never comes.
        $this->files->expects(self::exactly(25))->method('get')->willReturnCallback(
            fn (string $id): DriveFile => $this->file($id, 'Item', null, [$id . '-parent'])
        );
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));

        self::assertFalse($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testSearchEscapesBackslashesInTheQuery(): void
    {
        $captured = null;

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params;

                return $this->fileList([]);
            }
        );

        $this->service()->search('C:\Users');

        self::assertStringContainsString("name contains 'C:\\\\Users'", $captured['q']);
    }

    public function testListPermissionsStopsWhenGoogleKeepsPaginatingForever(): void
    {
        $endless = new PermissionList();
        $endless->setPermissions([]);
        $endless->setNextPageToken('AGAIN');
        $this->permissions->method('listPermissions')->willReturn($endless);

        $this->expectException(UnexpectedDriveStateException::class);

        $this->service()->listPermissions('doc');
    }

    public function testRevokeRecognisesInheritedPermissionsByGoogleReason(): void
    {
        $this->permissions->method('delete')->willThrowException(
            new GoogleServiceException('Forbidden', 403, null, [['reason' => 'cannotDeletePermission']])
        );

        $this->expectException(InheritedPermissionException::class);

        $this->service()->revoke('doc', 'perm-1');
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
