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
 * A listing and canAccess() must answer the same question the same way.
 *
 * Sharing a folder and letting its contents follow is the ordinary way to use a Shared Drive, and
 * for one release it produced a document that `canAccess()` allowed and no search would return:
 * the whole-drive listings filtered per item without walking up the parents, which stopped agreeing
 * the moment inherited grants were no longer cached under the child. Fail-closed, so nothing leaked
 * — but the document was gone from search, from the trash listing and from a lookup by application
 * property, which is most of how anyone finds anything.
 */
final class DriveDocumentServiceListingAgreementTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);

        // invoice.xlsx lives in folder-p, and the only grant is on the folder.
        $this->files->method('listFiles')->willReturnCallback(fn (): FileList => $this->listOf('invoice'));
        $this->files->method('get')->willReturnCallback(
            static fn (string $id): DriveFile => new DriveFile([
                'id'      => $id,
                'parents' => [$id === 'invoice' ? 'folder-p' : self::DRIVE_ID],
            ])
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => match ($id) {
                // Drive reports the folder's grant on the child, marked inherited.
                'invoice'  => $this->grantedTo('viewer@example.com', inheritedFrom: 'folder-p'),
                'folder-p' => $this->grantedTo('viewer@example.com'),
                default    => new PermissionList(),
            }
        );
    }

    public function testCanAccessAllowsADocumentSharedThroughItsFolder(): void
    {
        self::assertTrue($this->service()->canAccess('invoice'));
    }

    public function testSearchReturnsIt(): void
    {
        self::assertCount(1, $this->service()->search('invoice'));
    }

    public function testLookingItUpByApplicationPropertyReturnsIt(): void
    {
        self::assertCount(1, $this->service()->findByAppProperty('orderId', '4711'));
    }

    public function testTheTrashListingReturnsIt(): void
    {
        self::assertCount(1, $this->service()->listTrash());
    }

    public function testAPageOfResultsReturnsIt(): void
    {
        self::assertCount(1, $this->service()->searchPage('invoice')->items);
    }

    public function testADocumentSharedWithNobodyIsStillFilteredOut(): void
    {
        // The control: the walk must not turn filtering into a formality.
        $service = $this->service('stranger@example.com');

        self::assertFalse($service->canAccess('invoice'));
        self::assertSame([], $service->search('invoice'));
    }

    public function testTheAncestorChainIsReadOnceForAWholePage(): void
    {
        // Walking per item would be a folder lookup per item. The items in a page usually share
        // their folders, and the memo is what keeps the extra cost to the first one.
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);

        $this->files->method('listFiles')->willReturnCallback(
            fn (): FileList => $this->listOf('invoice-1', 'invoice-2', 'invoice-3')
        );

        $folderLookups = 0;
        $this->files->method('get')->willReturnCallback(
            function (string $id) use (&$folderLookups): DriveFile {
                if ($id === 'folder-p') {
                    ++$folderLookups;
                }

                return new DriveFile([
                    'id'      => $id,
                    'parents' => [str_starts_with($id, 'invoice') ? 'folder-p' : self::DRIVE_ID],
                ]);
            }
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $id): PermissionList => $id === 'folder-p'
                ? $this->grantedTo('viewer@example.com')
                : $this->grantedTo('viewer@example.com', inheritedFrom: 'folder-p')
        );

        self::assertCount(3, $this->service()->search('invoice'));
        self::assertSame(1, $folderLookups, 'the shared folder was read once for the page, not once per item');
    }

    private function service(string $viewer = 'viewer@example.com'): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext($viewer, false),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );
    }

    private function listOf(string ...$ids): FileList
    {
        $list = new FileList();
        $list->setFiles(array_map(
            static fn (string $id): DriveFile => new DriveFile([
                'id'       => $id,
                'name'     => $id . '.xlsx',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'parents'  => ['folder-p'],
            ]),
            $ids
        ));

        return $list;
    }

    private function grantedTo(string $email, ?string $inheritedFrom = null): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions([new GooglePermission([
            'emailAddress'      => $email,
            'type'              => DrivePermission::TYPE_USER,
            'role'              => 'reader',
            'permissionDetails' => $inheritedFrom === null
                ? [['inherited' => false]]
                : [['inherited' => true, 'inheritedFrom' => $inheritedFrom]],
        ])]);

        return $list;
    }
}
