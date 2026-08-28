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
