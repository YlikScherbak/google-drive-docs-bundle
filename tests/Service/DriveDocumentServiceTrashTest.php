<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\DocumentDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRestoredEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentTrashedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InsufficientDriveRoleException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\PermissionList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceTrashTest extends TestCase
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

    public function testTrashSetsTheTrashedFlagInsteadOfDeleting(): void
    {
        $this->files->expects(self::never())->method('delete');

        $payload = null;
        $params  = null;

        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file, array $options) use (&$payload, &$params): DriveFile {
                $payload = $file;
                $params  = $options;

                return $this->file('doc-1', 'Q3', trashed: true);
            }
        );

        $document = $this->service()->trash('doc-1');

        self::assertTrue($payload->getTrashed());
        self::assertTrue($params['supportsAllDrives']);
        self::assertTrue($document->trashed);
        self::assertSame('doc-1', $document->id);
    }

    public function testTrashDispatchesItsOwnEvent(): void
    {
        $this->files->method('update')->willReturn($this->file('doc-1', 'Q3', trashed: true));

        $this->service()->trash('doc-1');

        $event = $this->dispatcher->single(DocumentTrashedEvent::class);
        self::assertSame('doc-1', $event->fileId);
        self::assertTrue($event->document->trashed);
    }

    public function testTrashRequiresAccessToTheItem(): void
    {
        $this->denyAccess();

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->trash('doc-1');
    }

    public function testRestoreClearsTheTrashedFlag(): void
    {
        $payload = null;

        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return $this->file('doc-1', 'Q3');
            }
        );

        $document = $this->service()->restore('doc-1');

        self::assertFalse($payload->getTrashed());
        self::assertFalse($document->trashed);
    }

    public function testRestoreDispatchesItsOwnEvent(): void
    {
        $this->files->method('update')->willReturn($this->file('doc-1', 'Q3'));

        $this->service()->restore('doc-1');

        self::assertSame('doc-1', $this->dispatcher->single(DocumentRestoredEvent::class)->fileId);
    }

    public function testRestoreRequiresAccessToTheItem(): void
    {
        $this->denyAccess();

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->restore('doc-1');
    }

    public function testListTrashAsksGoogleOnlyForTrashedItems(): void
    {
        $captured = null;

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params;

                return $this->fileList([]);
            }
        );

        $this->service()->listTrash();

        self::assertStringContainsString('trashed=true', $captured['q']);
        self::assertStringNotContainsString('trashed=false', $captured['q']);
        self::assertSame('drive', $captured['corpora']);
        self::assertSame(self::DRIVE_ID, $captured['driveId']);
    }

    public function testListTrashMarksEveryItemAsTrashed(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('doc-1', 'Q3', trashed: true),
        ]));

        $items = $this->service()->listTrash();

        self::assertCount(1, $items);
        self::assertTrue($items[0]->trashed);
    }

    public function testListTrashFiltersPerItemForRestrictedViewers(): void
    {
        // No folder shortcut here: the trash is flat, so every entry is checked on its own.
        $this->files->method('listFiles')->willReturn($this->fileList([
            $this->file('mine', 'Mine', trashed: true),
            $this->file('foreign', 'Foreign', trashed: true),
        ]));

        $this->permissions->method('listPermissions')->willReturnCallback(
            fn (string $fileId): PermissionList => $this->permissionList(
                $fileId === 'mine' ? ['viewer@example.com'] : ['someone@example.com']
            )
        );

        $items = $this->service(new FakeViewerContext('viewer@example.com'))->listTrash();

        self::assertCount(1, $items);
        self::assertSame('mine', $items[0]->id);
    }

    public function testListTrashIsEmptyForAViewerWithoutIdentities(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        self::assertSame([], $this->service(new FakeViewerContext(null))->listTrash());
    }

    public function testListTrashNeedsAConfiguredDrive(): void
    {
        $this->expectException(NotConfiguredException::class);

        $this->service(null, '')->listTrash();
    }

    public function testDeleteForeverRemovesTheFileForGood(): void
    {
        $captured = null;

        $this->files->expects(self::once())->method('delete')->willReturnCallback(
            function (string $id, array $params) use (&$captured): void {
                $captured = [$id, $params];
            }
        );

        $this->service()->deleteForever('doc-1');

        self::assertSame('doc-1', $captured[0]);
        self::assertTrue($captured[1]['supportsAllDrives']);
        self::assertSame('doc-1', $this->dispatcher->single(DocumentDeletedEvent::class)->fileId);
    }

    public function testDeleteForeverExplainsAMissingManagerRole(): void
    {
        $this->files->method('delete')->willThrowException(
            new GoogleServiceException('The user does not have sufficient permissions for this file.', 403)
        );

        $this->expectException(InsufficientDriveRoleException::class);

        $this->service()->deleteForever('doc-1');
    }

    public function testDeleteForeverDoesNotMistakeAnExhaustedRateLimitForAMissingRole(): void
    {
        // Drive reports quota problems behind a 403; once the retries are spent it must surface as such.
        $this->files->method('delete')->willThrowException(
            new GoogleServiceException('Rate Limit Exceeded', 403, null, [['reason' => 'rateLimitExceeded']])
        );

        $this->expectException(GoogleServiceException::class);

        $this->service()->deleteForever('doc');
    }

    public function testDeleteForeverHandlesA403CarryingNoMachineReadableReasons(): void
    {
        // getErrors() is null whenever Google's body has no "error.errors"; checking whether
        // the 403 is a rate limit must not blow up on that.
        $this->files->method('delete')->willThrowException(
            new GoogleServiceException('The user does not have sufficient permissions for this file.', 403, null, null)
        );

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->service()->deleteForever('doc-1');
            self::fail('Expected the missing role to be reported.');
        } catch (InsufficientDriveRoleException $e) {
            self::assertStringContainsString('Manager', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testDeleteForeverLeavesOtherGoogleErrorsAlone(): void
    {
        $this->files->method('delete')->willThrowException(
            new GoogleServiceException('File not found: doc-1.', 404)
        );

        $this->expectException(GoogleServiceException::class);

        $this->service()->deleteForever('doc-1');
    }

    /**
     * @group legacy
     */
    public function testDeprecatedDeleteStillRemovesTheFileForGood(): void
    {
        $this->files->expects(self::once())->method('delete');
        $this->files->expects(self::never())->method('update');

        $this->service()->delete('doc-1');

        self::assertSame('doc-1', $this->dispatcher->single(DocumentDeletedEvent::class)->fileId);
    }

    public function testRegularListingsStillHideTrashedItems(): void
    {
        $captured = [];

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured[] = $params['q'];

                return $this->fileList([]);
            }
        );

        $service = $this->service();
        $service->listFolder();
        $service->search('Q3');

        self::assertStringContainsString('trashed=false', $captured[0]);
        self::assertStringContainsString('trashed=false', $captured[1]);
    }

    private function denyAccess(): void
    {
        $this->files->method('get')->willReturn($this->file('doc-1', 'Doc', parents: [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));
    }

    private function service(?ViewerContextInterface $context = null, string $driveId = self::DRIVE_ID): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            $driveId,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            $this->dispatcher
        );
    }

    /**
     * @param string[] $parents
     */
    private function file(
        string $id,
        string $name,
        ?string $mimeType = 'application/vnd.google-apps.spreadsheet',
        array $parents = [],
        bool $trashed = false
    ): DriveFile {
        return new DriveFile([
            'id'           => $id,
            'name'         => $name,
            'mimeType'     => $mimeType,
            'webViewLink'  => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
            'modifiedTime' => '2026-01-01T00:00:00.000Z',
            'parents'      => $parents,
            'trashed'      => $trashed,
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
     */
    private function permissionList(array $emails): PermissionList
    {
        $list = new PermissionList();
        $list->setPermissions(array_map(
            static fn (string $email): GooglePermission => new GooglePermission([
                'emailAddress' => $email,
                'type'         => 'user',
            ]),
            $emails
        ));

        return $list;
    }
}
