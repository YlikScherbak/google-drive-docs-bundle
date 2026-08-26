<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Event\DocumentLockChangedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Drive;
use Google\Service\Drive\Change;
use Google\Service\Drive\ChangeList;
use Google\Service\Drive\ContentRestriction;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Resource\Changes;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Drive\StartPageToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DriveDocumentServiceChangesTest extends TestCase
{
    private const DRIVE_ID    = 'SHARED_DRIVE_ID';
    private const SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private Changes&MockObject $changes;
    private CollectingEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->changes     = $this->createMock(Changes::class);
        $this->dispatcher  = new CollectingEventDispatcher();
    }

    // -------------------------------------------------------------- changes

    public function testAStartTokenIsWhereWatchingBegins(): void
    {
        $captured = null;
        $this->changes->method('getStartPageToken')->willReturnCallback(
            function (array $params) use (&$captured): StartPageToken {
                $captured = $params;

                return new StartPageToken(['startPageToken' => 'T-100']);
            }
        );

        self::assertSame('T-100', $this->service()->startPageToken());
        self::assertSame(self::DRIVE_ID, $captured['driveId']);
        self::assertTrue($captured['supportsAllDrives']);
    }

    public function testChangesComeBackWithTheTokenForNextTime(): void
    {
        $this->changes->method('listChanges')->willReturn($this->changeList(
            [$this->change('doc-1', 'Q3 report')],
            newStartPageToken: 'T-101'
        ));

        $changes = $this->service()->changesSince('T-100');

        self::assertCount(1, $changes->changes);
        self::assertSame('doc-1', $changes->changes[0]->fileId);
        self::assertSame('Q3 report', $changes->changes[0]->document?->name);
        self::assertFalse($changes->changes[0]->removed);
        self::assertSame('T-101', $changes->nextToken);
    }

    public function testARemovedItemHasNoDocument(): void
    {
        $removed = new Change(['fileId' => 'doc-9', 'removed' => true, 'time' => '2026-08-26T10:00:00.000Z']);
        $this->changes->method('listChanges')->willReturn($this->changeList([$removed], newStartPageToken: 'T-101'));

        $change = $this->service()->changesSince('T-100')->changes[0];

        self::assertTrue($change->removed);
        self::assertNull($change->document);
        self::assertSame('2026-08-26T10:00:00.000Z', $change->time);
    }

    public function testEveryPageIsWalkedBeforeTheTokenIsReturned(): void
    {
        $calls = 0;
        $this->changes->method('listChanges')->willReturnCallback(
            function (string $token, array $params) use (&$calls): ChangeList {
                ++$calls;

                if ($calls === 1) {
                    self::assertSame('T-100', $token);

                    return $this->changeList([$this->change('doc-1', 'One')], nextPageToken: 'P-2');
                }

                self::assertSame('P-2', $token);

                return $this->changeList([$this->change('doc-2', 'Two')], newStartPageToken: 'T-105');
            }
        );

        $changes = $this->service()->changesSince('T-100');

        self::assertCount(2, $changes->changes);
        self::assertSame('T-105', $changes->nextToken);
    }

    public function testAnEndlessPaginationIsCutOff(): void
    {
        $this->changes->method('listChanges')->willReturnCallback(
            fn (): ChangeList => $this->changeList([$this->change('doc', 'x')], nextPageToken: 'ALWAYS')
        );

        $this->expectException(UnexpectedDriveStateException::class);

        $this->service()->changesSince('T-100');
    }

    public function testGoogleFailingToHandBackATokenIsReported(): void
    {
        // Without a new token the caller has nowhere to resume from, and silently reusing the
        // old one would replay the same changes for ever.
        $this->changes->method('listChanges')->willReturn($this->changeList([]));

        $this->expectException(UnexpectedDriveStateException::class);
        $this->expectExceptionMessageMatches('/token/');

        $this->service()->changesSince('T-100');
    }

    public function testChangesAreScopedToTheConfiguredDrive(): void
    {
        $captured = null;
        $this->changes->method('listChanges')->willReturnCallback(
            function (string $token, array $params) use (&$captured): ChangeList {
                $captured = $params;

                return $this->changeList([], newStartPageToken: 'T-101');
            }
        );

        $this->service()->changesSince('T-100');

        self::assertSame(self::DRIVE_ID, $captured['driveId']);
        self::assertTrue($captured['includeItemsFromAllDrives']);
        self::assertTrue($captured['supportsAllDrives']);
    }

    public function testAChangeDropsTheCachedSharingOfThatItem(): void
    {
        // The point of polling: a share made directly in Drive is picked up now rather than
        // whenever the cache happens to expire.
        $pool = new ArrayAdapter();
        $item = $pool->getItem('google_drive_docs.grants.v2.' . sha1('doc-1'));
        $item->set(['stale@example.com' => 'writer']);
        $pool->save($item);

        $this->changes->method('listChanges')->willReturn($this->changeList(
            [$this->change('doc-1', 'Q3 report')],
            newStartPageToken: 'T-101'
        ));

        $this->service(pool: $pool)->changesSince('T-100');

        self::assertFalse($pool->getItem('google_drive_docs.grants.v2.' . sha1('doc-1'))->isHit());
    }

    public function testWatchingNeedsAConfiguredDrive(): void
    {
        $this->expectException(NotConfiguredException::class);

        $this->service(driveId: '')->startPageToken();
    }

    // ----------------------------------------------------------- locking

    public function testLockingMakesAnItemReadOnlyWithAReason(): void
    {
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return $this->lockedFile('Approved by finance');
            }
        );

        $document = $this->service()->lock('doc-1', 'Approved by finance');

        $restriction = $payload->getContentRestrictions()[0];
        self::assertTrue($restriction->getReadOnly());
        self::assertSame('Approved by finance', $restriction->getReason());

        self::assertTrue($document->locked);
        self::assertSame('Approved by finance', $document->lockReason);
    }

    public function testUnlockingClearsTheRestriction(): void
    {
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return $this->file('doc-1', 'Q3 report');
            }
        );

        $document = $this->service()->unlock('doc-1');

        self::assertFalse($payload->getContentRestrictions()[0]->getReadOnly());
        self::assertFalse($document->locked);
        self::assertNull($document->lockReason);
    }

    public function testLockingReportsBothDirections(): void
    {
        $this->files->method('update')->willReturn($this->lockedFile('Final'));

        $this->service()->lock('doc-1', 'Final');

        $event = $this->dispatcher->single(DocumentLockChangedEvent::class);
        self::assertTrue($event->locked);
        self::assertSame('Final', $event->reason);
    }

    public function testAnUnlockedItemReportsNoRestriction(): void
    {
        $this->files->method('get')->willReturn($this->file('doc-1', 'Q3 report'));

        $document = $this->service()->get('doc-1');

        self::assertFalse($document->locked);
        self::assertNull($document->lockReason);
    }

    public function testTheRequestedFieldsCoverTheRestriction(): void
    {
        $captured = null;
        $this->files->method('get')->willReturnCallback(
            function (string $id, array $params) use (&$captured): DriveFile {
                $captured = $params['fields'];

                return $this->file('doc-1', 'Q3 report');
            }
        );

        $this->service()->get('doc-1');

        self::assertStringContainsString('contentRestrictions(readOnly,reason)', $captured);
    }

    private function change(string $fileId, string $name): Change
    {
        $change = new Change([
            'fileId'  => $fileId,
            'removed' => false,
            'time'    => '2026-08-26T10:00:00.000Z',
        ]);
        $change->setFile($this->file($fileId, $name));

        return $change;
    }

    /**
     * @param Change[] $changes
     */
    private function changeList(
        array $changes,
        ?string $nextPageToken = null,
        ?string $newStartPageToken = null
    ): ChangeList {
        $list = new ChangeList();
        $list->setChanges($changes);

        if ($nextPageToken !== null) {
            $list->setNextPageToken($nextPageToken);
        }

        if ($newStartPageToken !== null) {
            $list->setNewStartPageToken($newStartPageToken);
        }

        return $list;
    }

    private function file(string $id, string $name): DriveFile
    {
        return new DriveFile(['id' => $id, 'name' => $name, 'mimeType' => self::SPREADSHEET]);
    }

    private function lockedFile(string $reason): DriveFile
    {
        $file = $this->file('doc-1', 'Q3 report');
        $file->setContentRestrictions([new ContentRestriction(['readOnly' => true, 'reason' => $reason])]);

        return $file;
    }

    private function service(
        string $driveId = self::DRIVE_ID,
        ?\Psr\Cache\CacheItemPoolInterface $pool = null
    ): DriveDocumentService {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;
        $drive->changes     = $this->changes;

        return new DriveDocumentService(
            $drive,
            new AllowAllViewerContext(),
            $driveId,
            [self::SPREADSHEET],
            false,
            $this->dispatcher,
            $pool,
            300
        );
    }
}
