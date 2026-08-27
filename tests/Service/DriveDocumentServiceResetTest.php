<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

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
use Symfony\Contracts\Service\ResetInterface;

/**
 * The sharing memo is documented as per-request, and under a worker runtime there is no such thing
 * unless something clears it.
 *
 * FrankenPHP, RoadRunner, Swoole and Messenger consumers all keep one service instance for the life
 * of the process. Without a reset the memo held sharing decisions until the worker restarted —
 * outliving the pool TTL, and outliving it even with no pool configured at all.
 */
final class DriveDocumentServiceResetTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testTheServiceCanBeReset(): void
    {
        self::assertInstanceOf(ResetInterface::class, $this->service());
    }

    public function testTheSharingMemoIsHeldWithinOneRequest(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList());
        // Twice through the same instance is one request: the second listing must be free.
        $this->permissions->expects(self::once())
            ->method('listPermissions')
            ->willReturn($this->permissionList());

        $service = $this->service();

        self::assertCount(1, $service->listFolder());
        self::assertCount(1, $service->listFolder());
    }

    public function testResettingForgetsIt(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList());
        // And after a reset the next request pays again, which is the whole point: a sharing change
        // made anywhere else is now visible to the worker without restarting it.
        $this->permissions->expects(self::exactly(2))
            ->method('listPermissions')
            ->willReturn($this->permissionList());

        $service = $this->service();

        self::assertCount(1, $service->listFolder());
        $service->reset();
        self::assertCount(1, $service->listFolder());
    }

    public function testAResetItemIsLookedUpAgainWithItsNewAnswer(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList());

        $calls = 0;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function () use (&$calls): PermissionList {
                ++$calls;

                return $calls === 1 ? $this->permissionList() : new PermissionList();
            }
        );

        $service = $this->service();

        self::assertCount(1, $service->listFolder());
        $service->reset();
        self::assertSame([], $service->listFolder(), 'the revoked access survived the reset');
    }

    private function service(): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext('viewer@example.com', false),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );
    }

    private function fileList(): FileList
    {
        $list = new FileList();
        $list->setFiles([new DriveFile([
            'id'       => 'doc-1',
            'name'     => 'Doc',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
            'parents'  => [self::DRIVE_ID],
        ])]);

        return $list;
    }

    private function permissionList(): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions([new GooglePermission([
            'emailAddress' => 'viewer@example.com',
            'type'         => DrivePermission::TYPE_USER,
            'role'         => 'writer',
        ])]);

        return $list;
    }
}
