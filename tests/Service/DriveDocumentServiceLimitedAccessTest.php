<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\PermissionPermissionDetails;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Folders that permissions from above do not reach.
 *
 * Drive calls them limited access and marks them `inheritedPermissionsDisabled`; only their own
 * grants, and an owner or organizer, reach what is inside. The walk up the parents has to stop
 * there, or a grant on an outer folder carries across a boundary Drive draws on purpose.
 *
 * Measured against a real drive before being written down here: Google does not report the outer
 * grant on the file inside. What it does is downgrade that grant **on the limited folder itself** to
 * a reader with `view=metadata` — enough to see the folder exists. So the way past the boundary is
 * to keep climbing to the outer folder, where the grant is direct and undowngraded.
 *
 *     outer/                 writer, direct
 *       limited/             inheritedPermissionsDisabled, inherited grant downgraded to metadata
 *         secret.xlsx        nothing
 */
final class DriveDocumentServiceLimitedAccessTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);

        $this->files->method('get')->willReturnCallback(
            static fn (string $id): DriveFile => match ($id) {
                'secret'  => new DriveFile(['id' => 'secret', 'parents' => ['limited']]),
                'limited' => new DriveFile([
                    'id'                           => 'limited',
                    'parents'                      => ['outer'],
                    'inheritedPermissionsDisabled' => true,
                ]),
                default   => new DriveFile(['id' => 'outer', 'parents' => [self::DRIVE_ID]]),
            }
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => match ($id) {
                // Nothing of its own.
                'secret'  => new PermissionList(),
                // What Drive really returns: the outer grant, downgraded and marked inherited.
                'limited' => $this->grant('reader', inheritedFrom: 'outer', view: 'metadata'),
                // The real grant, direct, on the folder above the boundary.
                default   => $this->grant('writer'),
            }
        );
    }

    public function testAGrantAboveTheBoundaryDoesNotReachInside(): void
    {
        self::assertFalse($this->service()->canAccess('secret'));
    }

    public function testNoRoleIsReportedInside(): void
    {
        self::assertNull($this->service()->roleOf('secret'));
    }

    public function testTheGrantStillWorksOnTheFolderItWasMadeOn(): void
    {
        // The control: the boundary must not swallow the grant where it really applies.
        self::assertTrue($this->service()->canAccess('outer'));
        self::assertSame('writer', $this->service()->roleOf('outer'));
    }

    public function testAMetadataOnlyGrantIsNotAccess(): void
    {
        // Seeing that a folder exists is not being able to open it, and the downgrade Drive applies
        // says exactly that. Reading it as access would take the refusal for permission.
        self::assertFalse($this->service()->canAccess('limited'));
    }

    public function testAMetadataOnlyGrantIsRefusedEvenWhenDriveOmitsItsOrigin(): void
    {
        // The downgraded grant is normally also marked inherited, and that mark is what used to
        // catch it. `view` has to catch it on its own: Drive does not owe permissionDetails on
        // every answer, and a check that depends on a second field is not a second line of defence.
        $this->permissions = $this->createMock(Permissions::class);
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $id === 'limited'
                ? $this->grant('reader', view: 'metadata')
                : new PermissionList()
        );

        self::assertFalse($this->service()->canAccess('limited'));
        self::assertNull($this->service()->roleOf('limited'));
    }

    public function testTheBoundaryIsReadOffAModelThatHasNoGetterForIt(): void
    {
        // The oldest google/apiclient-services this package allows predates both fields, so its
        // models keep them as loose data rather than declared properties. This is that model:
        // nothing declared, everything in the bag the SDK keeps for unknown keys.
        $this->files = $this->createMock(Files::class);
        $this->files->method('get')->willReturnCallback(
            static fn (string $id): DriveFile => match ($id) {
                'secret'  => new DriveFile(['id' => 'secret', 'parents' => ['limited']]),
                'limited' => self::withoutGetters(['id' => 'limited', 'parents' => ['outer'], 'inheritedPermissionsDisabled' => true]),
                default   => new DriveFile(['id' => 'outer', 'parents' => [self::DRIVE_ID]]),
            }
        );
        $this->permissions = $this->createMock(Permissions::class);
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => match ($id) {
                'limited' => $this->grantWithoutGetters('reader', view: 'metadata'),
                'outer'   => $this->grant('writer'),
                default   => new PermissionList(),
            }
        );

        self::assertFalse($this->service()->canAccess('secret'), 'the boundary must hold with no getter to read it through');
        self::assertFalse($this->service()->canAccess('limited'), 'so must the metadata refusal');
    }

    public function testADirectGrantInsideTheBoundaryStillWorks(): void
    {
        // What limited access is for: share the folder itself with the people who should have it.
        $this->permissions = $this->createMock(Permissions::class);
        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $id === 'secret'
                ? $this->grant('writer')
                : new PermissionList()
        );

        self::assertTrue($this->service()->canAccess('secret'));
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

    /**
     * A DriveFile as an SDK too old to declare the limited-access field would build it: the key
     * is not a property but lives in the bag Google\Model keeps for the keys it does not know,
     * reachable only through the model's own magic.
     *
     * @param array<string, mixed> $data
     */
    private static function withoutGetters(array $data): DriveFile
    {
        $file = new class () extends DriveFile {
            public function keepInTheBagOnly(string $key, mixed $value): void
            {
                // Forget the declared property, so isset() and reads fall through to the bag.
                unset($this->{$key});
                $this->modelData[$key] = $value;
            }
        };

        foreach ($data as $key => $value) {
            if ($key === 'inheritedPermissionsDisabled') {
                $file->keepInTheBagOnly($key, $value);
            } else {
                $file->{$key} = $value;
            }
        }

        return $file;
    }

    private function grantWithoutGetters(string $role, string $view): PermissionList
    {
        $permission = new class () extends GooglePermission {
            public function keepInTheBagOnly(string $key, mixed $value): void
            {
                unset($this->{$key});
                $this->modelData[$key] = $value;
            }
        };
        $permission->emailAddress      = 'viewer@example.com';
        $permission->type              = DrivePermission::TYPE_USER;
        $permission->role              = $role;
        $permission->permissionDetails = [new PermissionPermissionDetails(['inherited' => false])];
        $permission->keepInTheBagOnly('view', $view);

        $list = new PermissionList();
        $list->setPermissions([$permission]);

        return $list;
    }

    private function grant(string $role, ?string $inheritedFrom = null, ?string $view = null): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions([new GooglePermission(array_filter([
            'emailAddress'      => 'viewer@example.com',
            'type'              => DrivePermission::TYPE_USER,
            'role'              => $role,
            'view'              => $view,
            'permissionDetails' => $inheritedFrom === null
                ? [['inherited' => false]]
                : [['inherited' => true, 'inheritedFrom' => $inheritedFrom]],
        ], static fn (mixed $v): bool => $v !== null))]);

        return $list;
    }
}
