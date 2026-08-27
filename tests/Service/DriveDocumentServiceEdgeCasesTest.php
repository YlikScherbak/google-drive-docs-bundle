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

/**
 * The inputs and answers at the edges: an empty parent, the drive id used as one, a page token that
 * comes back empty, and a grant whose time is up but which Drive still lists.
 *
 * None of these is exotic. `?parentId=` in a query string is an empty string, not null; the README
 * says the drive root is addressable by the drive id; and Google may end a list either by omitting
 * the token or by sending an empty one.
 */
final class DriveDocumentServiceEdgeCasesTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testTheDriveIdNamesTheRootAndIsReachable(): void
    {
        // It used to answer false, because the walk stops at the root instead of examining it —
        // so every call that named the root explicitly was refused while the same call with null
        // went through.
        self::assertTrue($this->service()->canAccess(self::DRIVE_ID));
    }

    public function testAnEmptyParentIsTheSameAsNone(): void
    {
        $captured = null;
        $this->files->method('create')->willReturnCallback(
            function (DriveFile $file) use (&$captured): DriveFile {
                $captured = $file;

                return new DriveFile(['id' => 'new-1', 'name' => 'T', 'parents' => [self::DRIVE_ID]]);
            }
        );

        $this->service(admin: true)->createDocument('T', '');

        self::assertSame([self::DRIVE_ID], $captured->getParents());
    }

    public function testAnEmptyParentIsNotSentToGoogleWhenCopying(): void
    {
        // This one used to reach Drive as parents: [""].
        $this->files->method('get')->willReturn(new DriveFile([
            'id'       => 'doc-1',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ]));

        $captured = null;
        $this->files->method('copy')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$captured): DriveFile {
                $captured = $file;

                return new DriveFile(['id' => 'copy-1', 'name' => 'Copy']);
            }
        );

        $this->service(admin: true)->copy('doc-1', 'Copy', '');

        self::assertNull($captured->getParents());
    }

    public function testAnEmptyPageTokenEndsTheSharingLookup(): void
    {
        // Google ends a list either by omitting the token or by sending an empty one. Reading '' as
        // "there is more" asked for the same first page until the page budget ran out.
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'doc-1',
            'parents' => [self::DRIVE_ID],
        ]));

        $this->permissions->expects(self::once())
            ->method('listPermissions')
            ->willReturnCallback(function (): PermissionList {
                $list = new PermissionList();
                $list->setPermissions([]);
                $list->setNextPageToken('');

                return $list;
            });

        self::assertFalse($this->service()->canAccess('doc-1'));
    }

    public function testAnEmptyPageTokenEndsThePermissionListing(): void
    {
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc-1']));

        $this->permissions->expects(self::once())
            ->method('listPermissions')
            ->willReturnCallback(function (): PermissionList {
                $list = new PermissionList();
                $list->setPermissions([new GooglePermission([
                    'id'           => 'perm-1',
                    'emailAddress' => 'viewer@example.com',
                    'type'         => DrivePermission::TYPE_USER,
                    'role'         => 'writer',
                ])]);
                $list->setNextPageToken('');

                return $list;
            });

        self::assertCount(1, $this->service(admin: true)->listPermissions('doc-1'));
    }

    public function testAnExpiredGrantEmbeddedInTheFileOpensNothing(): void
    {
        // The dedicated lookup has always skipped a grant whose time is up. The grants Google
        // embeds in the file itself went unchecked, so an expired one still counted here.
        $this->files->method('get')->willReturn(new DriveFile([
            'id'          => 'doc-1',
            'parents'     => [self::DRIVE_ID],
            'permissions' => [[
                'emailAddress'   => 'viewer@example.com',
                'type'           => DrivePermission::TYPE_USER,
                'role'           => 'writer',
                'expirationTime' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::RFC3339),
            ]],
        ]));
        $this->permissions->method('listPermissions')->willReturn(new PermissionList());

        self::assertFalse($this->service()->canAccess('doc-1'));
    }

    public function testAGrantEmbeddedInTheFileStillCountsWhileItLasts(): void
    {
        $this->files->method('get')->willReturn(new DriveFile([
            'id'          => 'doc-1',
            'parents'     => [self::DRIVE_ID],
            'permissions' => [[
                'emailAddress'   => 'viewer@example.com',
                'type'           => DrivePermission::TYPE_USER,
                'role'           => 'writer',
                'expirationTime' => (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::RFC3339),
            ]],
        ]));

        self::assertTrue($this->service()->canAccess('doc-1'));
    }

    /**
     * @dataProvider tagged
     */
    public function testThePlusTagIsFoldedOnlyWhereGoogleFoldsIt(string $granted, string $viewer, bool $expected): void
    {
        // Folding +tag on every domain let alice+anything@corp.com answer for alice@corp.com, in
        // both directions — a way to be handed someone else's access on any domain that treats the
        // tag as part of the address.
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'doc-1',
            'parents' => [self::DRIVE_ID],
        ]));

        $list = new PermissionList();
        $list->setPermissions([new GooglePermission([
            'emailAddress' => $granted,
            'type'         => DrivePermission::TYPE_USER,
            'role'         => 'writer',
        ])]);
        $this->permissions->method('listPermissions')->willReturn($list);

        self::assertSame($expected, $this->service(viewer: $viewer)->canAccess('doc-1'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function tagged(): iterable
    {
        yield 'gmail folds the tag'          => ['alice@gmail.com', 'alice+news@gmail.com', true];
        yield 'and the other way round'      => ['alice+news@gmail.com', 'alice@gmail.com', true];
        yield 'googlemail too'               => ['alice@googlemail.com', 'alice+news@googlemail.com', true];
        yield 'a corporate domain does not'  => ['alice@corp.com', 'alice+intruder@corp.com', false];
        yield 'nor in reverse'               => ['alice+team@corp.com', 'alice@corp.com', false];
        yield 'the same address always does' => ['alice@corp.com', 'alice@corp.com', true];
    }

    private function service(bool $admin = false, string $viewer = 'viewer@example.com'): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext($viewer, $admin),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );
    }
}
