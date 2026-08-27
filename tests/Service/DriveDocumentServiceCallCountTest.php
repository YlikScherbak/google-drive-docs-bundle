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
 * How many times one request asks Google the same question.
 *
 * Access is decided by walking from the item up to the drive root, and one controller asks for that
 * walk more than once: the resolver fetches the document, the voter asks whether the viewer may
 * edit it, the action does the work. Each of those repeated the whole chain, so the count grew with
 * depth multiplied by however many times the question came up.
 *
 * These are exact numbers rather than bounds. A test that says "not too many" would keep passing
 * while the count doubled.
 */
final class DriveDocumentServiceCallCountTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private int $getCalls = 0;
    private int $permissionCalls = 0;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);

        // doc-1 sits three folders below the root, and the grant is on the topmost of them.
        $parents = [
            'doc-1'    => 'folder-a',
            'folder-a' => 'folder-b',
            'folder-b' => 'folder-c',
            'folder-c' => self::DRIVE_ID,
        ];

        $this->files->method('get')->willReturnCallback(
            function (string $id) use ($parents): DriveFile {
                ++$this->getCalls;

                return new DriveFile(['id' => $id, 'parents' => [$parents[$id] ?? self::DRIVE_ID]]);
            }
        );

        $this->permissions->method('listPermissions')->willReturnCallback(
            function (string $id): PermissionList {
                ++$this->permissionCalls;
                $list = new PermissionList();

                if ($id === 'folder-c') {
                    $list->setPermissions([new GooglePermission([
                        'emailAddress' => 'viewer@example.com',
                        'type'         => DrivePermission::TYPE_USER,
                        'role'         => 'writer',
                    ])]);
                }

                return $list;
            }
        );
    }

    public function testOneWalkIsPaidForOnce(): void
    {
        $service = $this->service();

        self::assertTrue($service->canAccess('doc-1'));

        // Four items from doc-1 up to the folder that holds the grant, each read once and asked
        // about once.
        self::assertSame(4, $this->getCalls);
        self::assertSame(4, $this->permissionCalls);
    }

    public function testAskingAgainCostsNothing(): void
    {
        $service = $this->service();

        $service->canAccess('doc-1');
        $service->canAccess('doc-1');
        $service->canAccess('doc-1');

        self::assertSame(4, $this->getCalls, 'the chain was walked again');
        self::assertSame(4, $this->permissionCalls);
    }

    public function testTheRoleQuestionReusesTheSameWalk(): void
    {
        // The shape of a real request: reach first, then the role, then the work.
        $service = $this->service();

        $service->canAccess('doc-1');
        self::assertSame('writer', $service->roleOf('doc-1'));

        // roleOf() reads every grant rather than stopping at the first, so it asks the sharing
        // question again — but the four files.get are already paid for.
        self::assertSame(4, $this->getCalls);
    }

    public function testASharingChangeDropsTheMemo(): void
    {
        $service = $this->service();

        $service->canAccess('doc-1');
        $before = $this->getCalls;

        $this->permissions->method('delete');
        $service->revoke('folder-c', 'perm-1');

        $service->canAccess('doc-1');

        self::assertGreaterThan($before, $this->getCalls, 'a revocation must not be answered from the memo');
    }

    public function testResettingDropsItToo(): void
    {
        $service = $this->service();

        $service->canAccess('doc-1');
        $service->reset();
        $service->canAccess('doc-1');

        self::assertSame(8, $this->getCalls);
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
}
