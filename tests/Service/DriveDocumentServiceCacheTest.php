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

        // Sharing is an administrator's job; the viewer's next listing must see it right away.
        $this->service(true, true)->grant('doc-1', 'viewer@example.com');

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

        $this->service(true, true)->revoke('doc-1', 'perm-1');

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

    public function testAZeroTtlKeepsTheSharingOutOfTheSharedPool(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));
        // Two requests, one pool, no lifetime: neither may read a stale entry left by the other.
        $this->permissions->expects(self::exactly(2))
            ->method('listPermissions')
            ->willReturn($this->permissionList(['viewer@example.com']));

        self::assertCount(1, $this->service(true, false, 0)->listFolder());
        self::assertCount(1, $this->service(true, false, 0)->listFolder());
        // The key carries the version, and leaving it out made this assertion pass on a
        // key that never existed.
        self::assertFalse($this->cache->hasItem('google_drive_docs.grants.v2.' . sha1('doc-1')));
    }

    public function testProgrammingErrorsInTheSharingLookupAreNotSwallowed(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc-1', 'Doc')]));
        $this->permissions->method('listPermissions')->willThrowException(new \TypeError('broken mapping'));

        $this->expectException(\TypeError::class);

        $this->service()->listFolder();
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

    public function testAnInheritedGrantIsNotCachedUnderTheChild(): void
    {
        // The one that made revoking on a folder a promise the cache did not keep. On a Shared
        // Drive, permissions.list on a child returns the grants it inherits as well; caching those
        // under the child's own key meant that clearing the folder's entry cleared nothing the
        // child would read, and the access outlived its revocation by up to the TTL.
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => new DriveFile([
                'id'      => $id,
                'parents' => [$id === 'child-1' ? 'folder-1' : self::DRIVE_ID],
            ])
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $id === 'child-1'
                // What Drive shows on the child: the folder's grant, marked as inherited.
                ? $this->permissionList(['viewer@example.com'], inherited: true)
                // And the folder itself, where the grant has just been revoked.
                : $this->permissionList([])
        );

        self::assertFalse(
            $this->service()->canAccess('child-1'),
            'a grant revoked on the folder still opened the file inside it'
        );
    }

    public function testMembershipOfTheDriveItselfStillCounts(): void
    {
        // Drive reports every member of the Shared Drive on every item, inherited from the drive.
        // That is not inheritance from a folder: the walk stops at the root without reading it, so
        // dropping the grant would leave it nowhere to be found and would hide documents from
        // people who can open them in Drive directly.
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'child-1',
            'parents' => [self::DRIVE_ID],
        ]));

        $list = new PermissionList();
        $list->setPermissions([new GooglePermission([
            'emailAddress'      => 'viewer@example.com',
            'type'              => DrivePermission::TYPE_USER,
            'role'              => 'writer',
            'permissionDetails' => [['inherited' => true, 'inheritedFrom' => self::DRIVE_ID]],
        ])]);
        $this->permissions->method('listPermissions')->willReturn($list);

        self::assertTrue($this->service()->canAccess('child-1'));
    }

    public function testTheAncestorGrantIsStillFound(): void
    {
        // The control, and the cost of the fix: the walk now has to reach the folder that holds
        // the grant rather than stopping at the child's copy of it.
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => new DriveFile([
                'id'      => $id,
                'parents' => [$id === 'child-1' ? 'folder-1' : self::DRIVE_ID],
            ])
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $id === 'child-1'
                ? $this->permissionList(['viewer@example.com'], inherited: true)
                : $this->permissionList(['viewer@example.com'])
        );

        self::assertTrue($this->service()->canAccess('child-1'));
    }

    public function testADirectGrantOnTheItemStillCounts(): void
    {
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'child-1',
            'parents' => [self::DRIVE_ID],
        ]));
        $this->permissions->method('listPermissions')
            ->willReturn($this->permissionList(['viewer@example.com']));

        self::assertTrue($this->service()->canAccess('child-1'));
    }

    private function service(bool $withCache = true, bool $asAdministrator = false, int $ttl = 300): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext($asAdministrator ? 'admin@example.com' : 'viewer@example.com', $asAdministrator),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            null,
            $withCache ? $this->cache : null,
            $ttl
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
     * @param bool     $inherited as Drive marks a grant that lives on an ancestor
     */
    private function permissionList(array $emails, bool $inherited = false): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions(array_map(
            static fn (string $email): GooglePermission => new GooglePermission([
                'emailAddress'      => $email,
                'type'              => DrivePermission::TYPE_USER,
                'role'              => 'writer',
                'permissionDetails' => $inherited
                    ? [['inherited' => true, 'inheritedFrom' => 'folder-1']]
                    : [['inherited' => false]],
            ]),
            $emails
        ));

        return $list;
    }
}
