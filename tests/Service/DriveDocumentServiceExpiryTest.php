<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\AccessGrantedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DriveDocumentServiceExpiryTest extends TestCase
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

    public function testAGrantCanBeGivenAnExpiry(): void
    {
        $payload = null;
        $this->permissions->method('create')->willReturnCallback(
            function (string $id, GooglePermission $permission) use (&$payload): GooglePermission {
                $payload = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $expires = new \DateTimeImmutable('+30 days');

        $this->service()->grant('doc', 'contractor@example.com', 'reader', expiresAt: $expires);

        self::assertSame($expires->format(\DateTimeInterface::RFC3339), $payload->getExpirationTime());
    }

    public function testAGrantWithoutAnExpirySendsNone(): void
    {
        $payload = null;
        $this->permissions->method('create')->willReturnCallback(
            function (string $id, GooglePermission $permission) use (&$payload): GooglePermission {
                $payload = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $this->service()->grant('doc', 'user@example.com');

        self::assertNull($payload->getExpirationTime());
    }

    public function testAGroupGrantCanExpireToo(): void
    {
        $payload = null;
        $this->permissions->method('create')->willReturnCallback(
            function (string $id, GooglePermission $permission) use (&$payload): GooglePermission {
                $payload = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $this->service()->grantToGroup('doc', 'team@example.com', 'reader', new \DateTimeImmutable('+7 days'));

        self::assertNotNull($payload->getExpirationTime());
        self::assertSame('group', $payload->getType());
    }

    public function testAServiceGrantCanExpireToo(): void
    {
        $payload = null;
        $this->permissions->method('create')->willReturnCallback(
            function (string $id, GooglePermission $permission) use (&$payload): GooglePermission {
                $payload = $permission;

                return new GooglePermission(['id' => 'perm-1']);
            }
        );

        $this->service()->grantAsService('doc', 'user@example.com', 'reader', 'user', new \DateTimeImmutable('+1 day'));

        self::assertNotNull($payload->getExpirationTime());
    }

    /**
     * @dataProvider impossibleExpiries
     */
    public function testAnExpiryGoogleWouldRefuseIsCaughtHere(string $when, string $because): void
    {
        // Google's own restrictions: the time must be in the future and no more than a year
        // ahead. Saying so here beats a Drive 400 that names neither.
        $this->permissions->expects(self::never())->method('create');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($because);

        $this->service()->grant('doc', 'user@example.com', 'reader', expiresAt: new \DateTimeImmutable($when));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function impossibleExpiries(): iterable
    {
        yield 'in the past'          => ['-1 day', '/future/'];
        yield 'right now'            => ['now', '/future/'];
        yield 'more than a year off' => ['+2 years', '/year/'];
    }

    public function testAnExpiryExactlyWithinTheYearIsAccepted(): void
    {
        $this->permissions->method('create')->willReturn(new GooglePermission(['id' => 'perm-1']));

        $this->service()->grant('doc', 'user@example.com', 'reader', expiresAt: new \DateTimeImmutable('+11 months'));

        self::assertSame('perm-1', $this->dispatcher->single(AccessGrantedEvent::class)->permission->id);
    }

    public function testListPermissionsRevealsWhenAGrantExpires(): void
    {
        $list = new PermissionList();
        $list->setPermissions([
            new GooglePermission([
                'id'             => 'perm-1',
                'emailAddress'   => 'contractor@example.com',
                'role'           => 'reader',
                'type'           => 'user',
                'expirationTime' => '2026-12-31T23:59:59Z',
            ]),
        ]);
        $this->permissions->method('listPermissions')->willReturn($list);

        $permission = $this->service()->listPermissions('doc')[0];

        self::assertSame('2026-12-31T23:59:59Z', $permission->expiresAt);
        self::assertTrue($permission->expires());
    }

    public function testAPermissionWithoutAnExpiryIsNotTemporary(): void
    {
        $permission = new DrivePermission('p', 'a@b.c', 'writer', 'user', null);

        self::assertNull($permission->expiresAt);
        self::assertFalse($permission->expires());
        self::assertNull($permission->toArray()['expiresAt']);
    }

    public function testAnExistingGrantCanBeGivenOrLoseAnExpiry(): void
    {
        $payload = null;
        $this->permissions->method('update')->willReturnCallback(
            function (string $file, string $perm, GooglePermission $body) use (&$payload): GooglePermission {
                $payload = $body;

                return new GooglePermission(['id' => 'perm-1', 'role' => 'reader', 'type' => 'user']);
            }
        );

        $expires = new \DateTimeImmutable('+3 days');
        $this->service()->setExpiry('doc', 'perm-1', $expires);
        self::assertSame($expires->format(\DateTimeInterface::RFC3339), $payload->getExpirationTime());

        // Null lifts the expiry, and the JSON that reaches Drive is what decides whether it
        // does: permissions.update is a PATCH, so a field left out of the body means "keep the
        // old value". The Google client drops PHP nulls when it serialises, which is why this
        // looks at the wire format rather than at the getter.
        $this->service()->setExpiry('doc', 'perm-1', null);

        $wire = json_decode(json_encode($payload->toSimpleObject(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('expirationTime', $wire, 'the field has to travel, or the old expiry stays');
        self::assertNull($wire['expirationTime']);
    }

    public function testSettingAnExpiryForgetsTheCachedSharing(): void
    {
        $this->permissions->method('update')->willReturn(new GooglePermission(['id' => 'perm-1']));

        $this->service()->setExpiry('doc', 'perm-1', new \DateTimeImmutable('+3 days'));

        self::assertSame('doc', $this->dispatcher->single(AccessGrantedEvent::class)->fileId);
    }

    public function testACachedGrantNeverOutlivesItsExpiry(): void
    {
        // The pool would keep the entry for the full TTL, long after Google dropped the grant;
        // the lifetime asked for has to be the time left on the grant instead.
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([
            ['viewer@example.com', (new \DateTimeImmutable('+60 seconds'))->format(\DateTimeInterface::RFC3339)],
        ]));

        $lifetime = null;
        $item     = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnCallback(
            function (mixed $time) use (&$lifetime, $item): CacheItemInterface {
                $lifetime = $time;

                return $item;
            }
        );
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $service = $this->service(new FakeViewerContext('viewer@example.com'), pool: $pool, ttl: 300);

        self::assertTrue($service->canAccess('doc'));
        self::assertIsInt($lifetime);
        self::assertLessThanOrEqual(60, $lifetime, 'the entry must go when the grant goes');
        self::assertGreaterThan(0, $lifetime);
    }

    public function testAGrantThatAlreadyExpiredDoesNotCount(): void
    {
        // Google removes an expired grant eventually, not instantly; until it does, the list
        // may still carry it, and it must not open anything.
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([
            ['viewer@example.com', (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::RFC3339)],
        ]));

        self::assertFalse($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testALastingGrantKeepsTheConfiguredLifetime(): void
    {
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([
            ['viewer@example.com', null],
        ]));

        $lifetime = null;
        $item     = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnCallback(
            function (mixed $time) use (&$lifetime, $item): CacheItemInterface {
                $lifetime = $time;

                return $item;
            }
        );
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $this->service(new FakeViewerContext('viewer@example.com'), pool: $pool, ttl: 300)->canAccess('doc');

        self::assertSame(300, $lifetime);
    }

    public function testSettingAnExpiryOnAnInheritedGrantIsExplained(): void
    {
        // Same translation revoke() already does: the grant lives on a parent folder, and
        // Google's reason for refusing is the thing to pass on, not the raw 403.
        $this->permissions->method('update')->willThrowException(
            new GoogleServiceException('Forbidden', 403, null, [['reason' => 'cannotModifyInheritedPermission']])
        );

        $this->expectException(InheritedPermissionException::class);

        $this->service()->setExpiry('doc', 'perm-1', new \DateTimeImmutable('+3 days'));
    }

    public function testAMalformedDriveIdIsAConfigurationProblem(): void
    {
        // It goes straight into every Drive query, so it is checked once, where it is set.
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/shared_drive_id/');

        $this->service(driveId: 'not a drive id')->listFolder();
    }

    private function service(
        ?ViewerContextInterface $context = null,
        string $driveId = self::DRIVE_ID,
        ?CacheItemPoolInterface $pool = null,
        int $ttl = 300
    ): DriveDocumentService {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            $driveId,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            $this->dispatcher,
            $pool,
            $ttl
        );
    }

    /**
     * @param list<array{0: string, 1: string|null}> $grants e-mail and RFC 3339 expiry, or null for a lasting one
     */
    private function permissionList(array $grants): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions(array_map(
            static fn (array $grant): GooglePermission => new GooglePermission(array_filter([
                'emailAddress'   => $grant[0],
                'type'           => DrivePermission::TYPE_USER,
                'role'           => DrivePermission::ROLE_READER,
                'expirationTime' => $grant[1],
            ], static fn (mixed $v): bool => $v !== null)),
            $grants
        ));

        return $list;
    }
}
