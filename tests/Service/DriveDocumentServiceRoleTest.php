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
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DriveDocumentServiceRoleTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testRoleOfReportsADirectGrant(): void
    {
        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => ['viewer@example.com' => 'writer']]);

        self::assertSame(
            DrivePermission::ROLE_WRITER,
            $this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc')
        );
    }

    public function testRoleOfPicksTheStrongestOfSeveralGrants(): void
    {
        // The viewer is named directly as a reader and reaches the same item through a
        // group that holds writer. Google resolves that to the stronger of the two.
        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => [
            'viewer@example.com'   => 'reader',
            'everyone@example.com' => 'writer',
        ]]);

        $context = new FakeViewerContext('viewer@example.com', false, ['everyone@example.com']);

        self::assertSame(DrivePermission::ROLE_WRITER, $this->service($context)->roleOf('doc'));
    }

    public function testRoleOfInheritsFromAnAncestorFolder(): void
    {
        $this->chain(['doc' => 'folder', 'folder' => self::DRIVE_ID]);
        $this->grants([
            'doc'    => [],
            'folder' => ['viewer@example.com' => 'commenter'],
        ]);

        self::assertSame(
            DrivePermission::ROLE_COMMENTER,
            $this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc')
        );
    }

    public function testRoleOfPrefersTheStrongerOfItemAndAncestor(): void
    {
        $this->chain(['doc' => 'folder', 'folder' => self::DRIVE_ID]);
        $this->grants([
            'doc'    => ['viewer@example.com' => 'reader'],
            'folder' => ['viewer@example.com' => 'writer'],
        ]);

        self::assertSame(
            DrivePermission::ROLE_WRITER,
            $this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc')
        );
    }

    public function testRoleOfKeepsRolesTheBundleItselfNeverGrants(): void
    {
        // organizer and fileOrganizer come from the Shared Drive's own membership. The bundle
        // cannot hand them out, but it must report them rather than pretend they are unknown.
        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => ['viewer@example.com' => 'organizer']]);

        self::assertSame(
            'organizer',
            $this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc')
        );
    }

    public function testRoleOfIsNullWhenTheItemIsNotSharedWithTheViewer(): void
    {
        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => ['someone@example.com' => 'writer']]);

        self::assertNull($this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc'));
    }

    public function testRoleOfIsNullForAViewerWithoutIdentities(): void
    {
        $this->files->expects(self::never())->method('get');

        self::assertNull($this->service(new FakeViewerContext(null))->roleOf('doc'));
    }

    public function testRoleOfIsNullForAViewerWhoBypassesFiltering(): void
    {
        // seesEverything() means the bundle never asks Google who this person is, so there is
        // no role to report — and none to act on, since the call runs as the service user.
        $this->files->expects(self::never())->method('get');
        $this->permissions->expects(self::never())->method('listPermissions');

        self::assertNull($this->service(new AllowAllViewerContext())->roleOf('doc'));
    }

    public function testTheSharingLookupAsksGoogleForTheRole(): void
    {
        $captured = null;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function (string $id, array $params) use (&$captured): PermissionList {
                $captured = $params['fields'];

                return new PermissionList();
            }
        );
        $this->chain(['doc' => self::DRIVE_ID]);

        $this->service(new FakeViewerContext('viewer@example.com'))->roleOf('doc');

        self::assertStringContainsString('role', $captured);
    }

    public function testAccessStillOnlyNeedsAGrantOfAnyRole(): void
    {
        // Exposing the role must not start enforcing it: a reader still passes canAccess().
        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => ['viewer@example.com' => 'reader']]);

        self::assertTrue($this->service(new FakeViewerContext('viewer@example.com'))->canAccess('doc'));
    }

    public function testGrantsCachedByAnOlderVersionAreIgnored(): void
    {
        // 0.4.0 cached a plain list of e-mails under its own key. Reading that as a
        // role map would be nonsense, so the key carries a version and the old entry is missed.
        $pool = new ArrayAdapter();
        $stale = $pool->getItem('google_drive_docs.grants.' . sha1('doc'));
        $stale->set(['viewer@example.com']);
        $pool->save($stale);

        $this->chain(['doc' => self::DRIVE_ID]);
        $this->grants(['doc' => ['viewer@example.com' => 'writer']]);

        $service = $this->service(new FakeViewerContext('viewer@example.com'), $pool);

        self::assertSame(DrivePermission::ROLE_WRITER, $service->roleOf('doc'));
    }

    /**
     * files->get answers the parent chain: ['child' => 'parent', ...].
     *
     * @param array<string, string> $parents
     */
    private function chain(array $parents): void
    {
        $this->files->method('get')->willReturnCallback(
            fn (string $id): DriveFile => new DriveFile([
                'id'      => $id,
                'parents' => isset($parents[$id]) ? [$parents[$id]] : [],
            ])
        );
    }

    /**
     * permissions->listPermissions answers per file: ['fileId' => ['email' => 'role']].
     *
     * @param array<string, array<string, string>> $byFile
     */
    private function grants(array $byFile): void
    {
        $this->permissions->method('listPermissions')->willReturnCallback(
            function (string $fileId) use ($byFile): PermissionList {
                $list = new PermissionList();
                $entries = [];

                foreach ($byFile[$fileId] ?? [] as $email => $role) {
                    $entries[] = new GooglePermission([
                        'emailAddress' => $email,
                        'type'         => 'user',
                        'role'         => $role,
                    ]);
                }

                $list->setPermissions($entries);

                return $list;
            }
        );
    }

    public function testTheStrongestRoleWinsEvenWhenGoogleEmbedsOnlyTheWeakOne(): void
    {
        // The permissions Google embeds in a file are not always the whole list, so a role
        // found there must not stop the dedicated lookup that may know a stronger one.
        $file = new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]);
        $file->setPermissions([
            new GooglePermission(['emailAddress' => 'viewer@example.com', 'type' => 'user', 'role' => 'reader']),
        ]);

        $this->files->method('get')->willReturn($file);
        $this->grants(['doc' => ['team@example.com' => 'writer']]);

        $service = $this->service(new FakeViewerContext('viewer@example.com', false, ['team@example.com']));

        self::assertSame('writer', $service->roleOf('doc'));
    }

    private function service(
        ?ViewerContextInterface $context = null,
        ?\Psr\Cache\CacheItemPoolInterface $pool = null
    ): DriveDocumentService {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            null,
            $pool,
            300
        );
    }
}
