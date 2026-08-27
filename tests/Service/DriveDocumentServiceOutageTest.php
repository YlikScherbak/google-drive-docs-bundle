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
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * What a Google outage inside the sharing lookup should look like from the outside.
 *
 * The two paths want opposite things, and giving them both the same answer was the defect. A
 * listing hides what it cannot check, because losing one lookup must not lose the page. A question
 * about one item has to surface the failure: answering "not shared with you" turns an outage into a
 * denial, and the caller cannot tell the difference.
 */
final class DriveDocumentServiceOutageTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
    }

    public function testAnOutageOnASingleItemSurfacesInsteadOfDenying(): void
    {
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'doc-1',
            'parents' => [self::DRIVE_ID],
        ]));
        $this->permissions->method('listPermissions')
            ->willThrowException(new GoogleServiceException('Backend Error', 503));

        $this->expectException(GoogleServiceException::class);

        $this->service()->canAccess('doc-1');
    }

    public function testRoleOfAlsoSurfacesIt(): void
    {
        $this->files->method('get')->willReturn(new DriveFile([
            'id'      => 'doc-1',
            'parents' => [self::DRIVE_ID],
        ]));
        $this->permissions->method('listPermissions')
            ->willThrowException(new GoogleServiceException('Backend Error', 503));

        $this->expectException(GoogleServiceException::class);

        $this->service()->roleOf('doc-1');
    }

    public function testAListingStillHidesWhatItCannotCheck(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList(['doc-1', 'doc-2']));
        $this->permissions->method('listPermissions')
            ->willThrowException(new GoogleServiceException('Backend Error', 503));

        // Losing one lookup must not lose the page, so the items are hidden and the call returns.
        self::assertSame([], $this->service()->listFolder());
    }

    public function testTheLookupIsNotRetriedOncePerItemForTheRestOfTheRequest(): void
    {
        // The availability half: each lookup carries its own ladder of retries inside the Google
        // client, so repeating it per item is what made a page of a thousand items sleep for
        // hours and then return nothing. The first failure is the answer for the whole request.
        $this->files->method('listFiles')->willReturn($this->fileList(['doc-1', 'doc-2', 'doc-3', 'doc-4']));

        $this->permissions->expects(self::once())
            ->method('listPermissions')
            ->willThrowException(new GoogleServiceException('Rate Limit Exceeded', 429));

        self::assertSame([], $this->service()->listFolder());
    }

    public function testTheHiddenFailureIsRecordedSomewhere(): void
    {
        // Hiding a document because Google was briefly unavailable looks exactly like the document
        // not being shared. Nothing else in the bundle records the difference.
        $this->files->method('listFiles')->willReturn($this->fileList(['doc-1']));
        $this->permissions->method('listPermissions')
            ->willThrowException(new GoogleServiceException('Backend Error', 503));

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $this->service($logger)->listFolder();

        self::assertCount(1, $logger->records);
        self::assertSame('doc-1', $logger->records[0]['context']['fileId']);
        self::assertSame(503, $logger->records[0]['context']['code']);
    }

    public function testAFreshRequestTriesAgain(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList(['doc-1']));

        $calls = 0;
        $this->permissions->method('listPermissions')->willReturnCallback(
            function () use (&$calls): PermissionList {
                if (++$calls === 1) {
                    throw new GoogleServiceException('Backend Error', 503);
                }

                $list = new PermissionList();
                $list->setPermissions([new GooglePermission([
                    'emailAddress' => 'viewer@example.com',
                    'type'         => DrivePermission::TYPE_USER,
                    'role'         => 'writer',
                ])]);

                return $list;
            }
        );

        $service = $this->service();

        self::assertSame([], $service->listFolder());
        // The skip lasts for the request, not for the process.
        $service->reset();
        self::assertCount(1, $service->listFolder());
    }

    private function service(?\Psr\Log\LoggerInterface $logger = null): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            new FakeViewerContext('viewer@example.com', false),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            null,
            null,
            300,
            0,
            8 * 1024 * 1024,
            $logger
        );
    }

    /**
     * @param string[] $ids
     */
    private function fileList(array $ids): FileList
    {
        $list = new FileList();
        $list->setFiles(array_map(
            static fn (string $id): DriveFile => new DriveFile([
                'id'       => $id,
                'name'     => $id,
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'parents'  => [self::DRIVE_ID],
            ]),
            $ids
        ));

        return $list;
    }
}
