<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sharing with Google groups: one grant covers a whole team.
 */
final class DriveDocumentServiceGroupsTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testGrantToGroupSendsTheGroupType(): void
    {
        $captured = null;

        $this->permissions->method('create')->willReturnCallback(
            function (string $fileId, GooglePermission $permission) use (&$captured): GooglePermission {
                $captured = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $permission = $this->service()->grantToGroup('folder-1', 'portugal@example.com', 'reader');

        self::assertSame(DrivePermission::TYPE_GROUP, $captured->getType());
        self::assertSame('portugal@example.com', $captured->getEmailAddress());
        self::assertSame('reader', $captured->getRole());
        // Google may answer without type/role for group grants — the request wins.
        self::assertSame(DrivePermission::TYPE_GROUP, $permission->type);
        self::assertSame('reader', $permission->role);
    }

    public function testGrantRejectsAnUnknownGranteeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->grant('doc-1', 'someone@example.com', 'writer', 'domain');
    }

    public function testItemsSharedWithTheViewerGroupAreVisible(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('portugal-folder', 'Portugal'),
            $this->file('italy-folder', 'Italy'),
        ]));

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $fileId): PermissionList => $this->permissionList(
                $fileId === 'portugal-folder'
                    ? [['portugal@example.com', DrivePermission::TYPE_GROUP]]
                    : [['italy@example.com', DrivePermission::TYPE_GROUP]]
            )
        );

        $context = new FakeViewerContext('member@example.com', false, ['portugal@example.com']);

        $items = $this->service($context)->listFolder();

        self::assertCount(1, $items);
        self::assertSame('portugal-folder', $items[0]->id);
    }

    public function testGroupMembershipGrantsAccessThroughTheParentChain(): void
    {
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => match ($id) {
                'doc'    => $this->file('doc', 'Doc', ['folder']),
                'folder' => $this->file('folder', 'Portugal', [self::DRIVE_ID]),
                default  => throw new \LogicException('unexpected id ' . $id),
            }
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $fileId): PermissionList => $this->permissionList(
                $fileId === 'folder' ? [['portugal@example.com', DrivePermission::TYPE_GROUP]] : []
            )
        );

        $context = new FakeViewerContext('member@example.com', false, ['portugal@example.com']);

        self::assertTrue($this->service($context)->canAccess('doc'));
    }

    public function testAViewerWithoutTheGroupSeesNothing(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('portugal-folder', 'Portugal'),
        ]));
        $this->permissions->method('listPermissions')->willReturn(
            $this->permissionList([['portugal@example.com', DrivePermission::TYPE_GROUP]])
        );

        $context = new FakeViewerContext('outsider@example.com', false, ['italy@example.com']);

        self::assertSame([], $this->service($context)->listFolder());
    }

    public function testAViewerWithGroupsButNoEmailStillMatches(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('portugal-folder', 'Portugal'),
        ]));
        $this->permissions->method('listPermissions')->willReturn(
            $this->permissionList([['portugal@example.com', DrivePermission::TYPE_GROUP]])
        );

        $context = new FakeViewerContext(null, false, ['portugal@example.com']);

        self::assertCount(1, $this->service($context)->listFolder());
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
            false
        );
    }

    /**
     * @param string[] $parents
     */
    private function file(string $id, string $name, array $parents = []): DriveFile
    {
        return new DriveFile([
            'id'       => $id,
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => $parents,
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
     * @param array<int, array{0: string, 1: string}> $entries e-mail + type pairs
     */
    private function permissionList(array $entries): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions(array_map(
            static fn (array $entry): GooglePermission => new GooglePermission([
                'emailAddress' => $entry[0],
                'type'         => $entry[1],
                'role'         => 'writer',
            ]),
            $entries
        ));

        return $list;
    }
}
