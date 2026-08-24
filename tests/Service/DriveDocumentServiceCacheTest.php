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
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Visibility filtering asks Google for the sharing of every listed item, so the
 * result is cached. The cache must never outlive a sharing change made through the bundle.
 */
final class DriveDocumentServiceCacheTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->cache       = new ArrayAdapter();
    }

    public function testSharingIsLookedUpOnceAcrossRequests(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));

        // Two separate service instances = two requests sharing the same pool.
        $this->permissions->expects(self::once())
            ->method('listPermissions')
            ->willReturn($this->permissionList(['viewer@example.com']));

        self::assertCount(1, $this->service()->listFolder());
        self::assertCount(1, $this->service()->listFolder());
    }

    public function testGrantingAccessInvalidatesTheCachedSharing(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));

        $calls = 0;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function () use (&$calls): PermissionList {
                ++$calls;

                // Before the grant nobody has access, afterwards the viewer does.
                return $this->permissionList($calls === 1 ? [] : ['viewer@example.com']);
            }
        );
        $this->permissions->method('create')->willReturn(new GooglePermission(['id' => 'perm-1']));

        self::assertSame([], $this->service()->listFolder());

        $this->service()->grant('doc-1', 'viewer@example.com');

        self::assertCount(1, $this->service()->listFolder(), 'the new grant must be visible immediately');
        self::assertSame(2, $calls);
    }

    public function testRevokingAccessInvalidatesTheCachedSharing(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));

        $calls = 0;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function () use (&$calls): PermissionList {
                ++$calls;

                return $this->permissionList($calls === 1 ? ['viewer@example.com'] : []);
            }
        );

        self::assertCount(1, $this->service()->listFolder());

        $this->service()->revoke('doc-1', 'perm-1');

        self::assertSame([], $this->service()->listFolder(), 'the revoked access must disappear immediately');
    }

    public function testApiFailuresAreNotCached(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));

        $calls = 0;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function () use (&$calls): PermissionList {
                ++$calls;

                if ($calls === 1) {
                    throw new \RuntimeException('Drive is unavailable');
                }

                return $this->permissionList(['viewer@example.com']);
            }
        );

        self::assertSame([], $this->service()->listFolder());
        // A transient error must not hide the document for the whole TTL.
        self::assertCount(1, $this->service()->listFolder());
    }

    public function testTheServiceWorksWithoutACachePool(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));
        $this->permissions->expects(self::exactly(2))
            ->method('listPermissions')
            ->willReturn($this->permissionList(['viewer@example.com']));

        self::assertCount(1, $this->service(false)->listFolder());
        self::assertCount(1, $this->service(false)->listFolder());
    }

    private function service(bool $withCache = true): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext('viewer@example.com'),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            null,
            $withCache ? $this->cache : null,
            300
        );
    }

    private function file(string $id, string $name): DriveFile
    {
        return new DriveFile([
            'id'       => $id,
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
            'parents'  => [self::DRIVE_ID],
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
                'type'         => DrivePermission::TYPE_USER,
                'role'         => 'writer',
            ]),
            $emails
        ));

        return $list;
    }
}
