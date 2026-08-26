<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\RevisionDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\RevisionKeptEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Drive\Resource\Revisions;
use Google\Service\Drive\Revision;
use Google\Service\Drive\RevisionList;
use Google\Service\Drive\User;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceRevisionsTest extends TestCase
{
    private const DRIVE_ID    = 'SHARED_DRIVE_ID';
    private const SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private Revisions&MockObject $revisions;
    private Client&MockObject $client;
    private CollectingEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->revisions   = $this->createMock(Revisions::class);
        $this->client      = $this->createMock(Client::class);
        $this->dispatcher  = new CollectingEventDispatcher();
    }

    // ------------------------------------------------------------- listing

    public function testRevisionsAreListedNewestLast(): void
    {
        $this->revisions->method('listRevisions')->willReturn($this->revisionList([
            $this->revision('r1', '2026-08-01T10:00:00.000Z'),
            $this->revision('r2', '2026-08-02T10:00:00.000Z'),
        ]));

        $found = $this->service()->listRevisions('doc');

        self::assertCount(2, $found);
        self::assertSame('r1', $found[0]->id);
        self::assertSame('2026-08-02T10:00:00.000Z', $found[1]->modifiedTime);
    }

    public function testARevisionCarriesWhoSavedItAndWhetherItIsPinned(): void
    {
        $this->revisions->method('listRevisions')->willReturn($this->revisionList([
            $this->revision('r1', '2026-08-01T10:00:00.000Z', keepForever: true, size: '2048'),
        ]));

        $revision = $this->service()->listRevisions('doc')[0];

        self::assertSame('Ada Lovelace', $revision->modifiedBy);
        self::assertTrue($revision->keptForever);
        self::assertSame(2048, $revision->size);
    }

    public function testAGoogleDocumentRevisionReportsNoSizeButExportLinks(): void
    {
        $revision = new Revision([
            'id'          => 'r1',
            'mimeType'    => self::SPREADSHEET,
            'exportLinks' => [DriveExport::XLSX => 'https://export.example/r1.xlsx'],
        ]);
        $this->revisions->method('listRevisions')->willReturn($this->revisionList([$revision]));

        $found = $this->service()->listRevisions('doc')[0];

        self::assertNull($found->size);
        self::assertTrue($found->isExportable());
        self::assertSame(['https://export.example/r1.xlsx'], array_values($found->exportLinks));
    }

    public function testListingWalksThePagesGoogleReturns(): void
    {
        $calls = 0;
        $this->revisions->method('listRevisions')->willReturnCallback(
            function (string $id, array $params) use (&$calls): RevisionList {
                ++$calls;

                if ($calls === 1) {
                    return $this->revisionList([$this->revision('r1', null)], 'TOKEN-2');
                }

                self::assertSame('TOKEN-2', $params['pageToken']);

                return $this->revisionList([$this->revision('r2', null)]);
            }
        );

        self::assertCount(2, $this->service()->listRevisions('doc'));
    }

    public function testListingStopsOnARunawayPagination(): void
    {
        $this->revisions->method('listRevisions')->willReturnCallback(
            fn (): RevisionList => $this->revisionList([$this->revision('r', null)], 'ALWAYS')
        );

        $this->expectException(UnexpectedDriveStateException::class);

        $this->service()->listRevisions('doc');
    }

    public function testListingRequiresAccess(): void
    {
        $this->denyAccess();
        $this->revisions->expects(self::never())->method('listRevisions');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->listRevisions('doc');
    }

    public function testASingleRevisionIsRead(): void
    {
        $this->revisions->method('get')->willReturn($this->revision('r1', '2026-08-01T10:00:00.000Z'));

        self::assertSame('r1', $this->service()->revision('doc', 'r1')->id);
    }

    // -------------------------------------------------------------- pinning

    public function testPinningARevisionAsksGoogleToKeepIt(): void
    {
        $payload = null;
        $this->revisions->method('update')->willReturnCallback(
            function (string $file, string $rev, Revision $body) use (&$payload): Revision {
                $payload = $body;

                return $this->revision('r1', null, keepForever: true);
            }
        );

        $revision = $this->service()->keepRevision('doc', 'r1');

        self::assertTrue($payload->getKeepForever());
        self::assertTrue($revision->keptForever);

        $event = $this->dispatcher->single(RevisionKeptEvent::class);
        self::assertSame('r1', $event->revisionId);
        self::assertTrue($event->kept);
    }

    public function testUnpinningIsTheSameCallTheOtherWayRound(): void
    {
        $payload = null;
        $this->revisions->method('update')->willReturnCallback(
            function (string $file, string $rev, Revision $body) use (&$payload): Revision {
                $payload = $body;

                return $this->revision('r1', null);
            }
        );

        $this->service()->keepRevision('doc', 'r1', false);

        self::assertFalse($payload->getKeepForever());
        self::assertFalse($this->dispatcher->single(RevisionKeptEvent::class)->kept);
    }

    // ------------------------------------------------------------- deleting

    public function testDeletingARevisionReportsIt(): void
    {
        $this->revisions->expects(self::once())->method('delete');

        $this->service()->deleteRevision('doc', 'r1');

        self::assertSame('r1', $this->dispatcher->single(RevisionDeletedEvent::class)->revisionId);
    }

    public function testDeletingARevisionRequiresAccess(): void
    {
        $this->denyAccess();
        $this->revisions->expects(self::never())->method('delete');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->deleteRevision('doc', 'r1');
    }

    // ------------------------------------------------------------ exporting

    public function testExportingAGoogleDocumentRevisionFetchesItsExportLink(): void
    {
        // A Google format has no stored bytes; the revision carries links instead.
        $this->revisions->method('get')->willReturn(new Revision([
            'id'          => 'r1',
            'mimeType'    => self::SPREADSHEET,
            'exportLinks' => [DriveExport::XLSX => 'https://export.example/r1.xlsx'],
        ]));
        $this->files->method('get')->willReturn($this->file('doc', 'Q3 report'));

        $asked = null;
        $http  = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url) use (&$asked): HttpResponse {
                $asked = [$method, $url];

                return new HttpResponse(200, [], 'old-xlsx-bytes');
            }
        );
        $this->client->method('authorize')->willReturn($http);

        $export = $this->service()->exportRevision('doc', 'r1', DriveExport::XLSX);

        self::assertSame(['GET', 'https://export.example/r1.xlsx'], $asked);
        self::assertSame('old-xlsx-bytes', $export->contents());
        self::assertSame('Q3 report.xlsx', $export->filename);
    }

    public function testExportingAPlainFileRevisionDownloadsItsBytes(): void
    {
        $this->revisions->method('get')->willReturnCallback(
            function (string $file, string $rev, array $params) {
                if (($params['alt'] ?? null) === 'media') {
                    return new HttpResponse(200, [], 'old-pdf-bytes');
                }

                return new Revision(['id' => 'r1', 'mimeType' => 'application/pdf', 'size' => '900']);
            }
        );
        $this->files->method('get')->willReturn($this->file('doc', 'Scan.pdf', 'application/pdf'));

        $export = $this->service()->exportRevision('doc', 'r1');

        self::assertSame('old-pdf-bytes', $export->contents());
        self::assertSame('application/pdf', $export->mimeType);
    }

    public function testExportingRefusesAFormatTheRevisionDoesNotOffer(): void
    {
        $this->revisions->method('get')->willReturn(new Revision([
            'id'          => 'r1',
            'mimeType'    => self::SPREADSHEET,
            'exportLinks' => [DriveExport::XLSX => 'https://export.example/r1.xlsx'],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/text\/csv/');

        $this->service()->exportRevision('doc', 'r1', DriveExport::CSV);
    }

    public function testExportingRequiresAccess(): void
    {
        $this->denyAccess();
        $this->revisions->expects(self::never())->method('get');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->exportRevision('doc', 'r1');
    }

    private function denyAccess(): void
    {
        $this->files->method('get')->willReturn($this->file('doc', 'Doc', parents: [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn(new PermissionList());
    }

    private function revision(
        string $id,
        ?string $modifiedTime,
        bool $keepForever = false,
        ?string $size = null
    ): Revision {
        $revision = new Revision([
            'id'           => $id,
            'modifiedTime' => $modifiedTime,
            'keepForever'  => $keepForever,
            'size'         => $size,
            'mimeType'     => self::SPREADSHEET,
        ]);

        $revision->setLastModifyingUser(new User([
            'displayName'  => 'Ada Lovelace',
            'emailAddress' => 'ada@example.com',
        ]));

        return $revision;
    }

    /**
     * @param Revision[] $revisions
     */
    private function revisionList(array $revisions, ?string $nextPageToken = null): RevisionList
    {
        $list = new RevisionList();
        $list->setRevisions($revisions);

        if ($nextPageToken !== null) {
            $list->setNextPageToken($nextPageToken);
        }

        return $list;
    }

    /**
     * @param string[] $parents
     */
    private function file(
        string $id,
        string $name,
        string $mimeType = self::SPREADSHEET,
        array $parents = []
    ): DriveFile {
        return new DriveFile([
            'id'       => $id,
            'name'     => $name,
            'mimeType' => $mimeType,
            'parents'  => $parents,
        ]);
    }

    private function service(?ViewerContextInterface $context = null): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;
        $drive->revisions   = $this->revisions;
        $drive->method('getClient')->willReturn($this->client);

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            self::DRIVE_ID,
            [self::SPREADSHEET],
            false,
            $this->dispatcher
        );
    }
}
