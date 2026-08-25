<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Model\DrivePage;
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

final class DriveDocumentServicePaginationTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->requests    = [];
    }

    public function testListFolderPageAsksGoogleExactlyOnce(): void
    {
        $this->respondWith([
            $this->fileList([$this->file('doc-1', 'A')], 'TOKEN-2'),
        ]);

        $this->files->expects(self::once())->method('listFiles');

        $page = $this->service()->listFolderPage();

        self::assertInstanceOf(DrivePage::class, $page);
        self::assertCount(1, $page->items);
        self::assertSame('TOKEN-2', $page->nextPageToken);
        self::assertTrue($page->hasMore());
    }

    public function testLastPageReportsNoMore(): void
    {
        $this->respondWith([$this->fileList([$this->file('doc-1', 'A')], null)]);

        $page = $this->service()->listFolderPage();

        self::assertNull($page->nextPageToken);
        self::assertFalse($page->hasMore());
    }

    public function testAnEmptyGoogleTokenCountsAsTheLastPage(): void
    {
        // Google may answer with an empty string rather than omitting the field.
        $this->respondWith([$this->fileList([$this->file('doc-1', 'A')], '')]);

        self::assertFalse($this->service()->listFolderPage()->hasMore());
    }

    public function testListFolderPageForwardsTheTokenToGoogle(): void
    {
        $this->respondWith([$this->fileList([], null)]);

        $this->service()->listFolderPage(null, 'TOKEN-2');

        self::assertSame('TOKEN-2', $this->requests[0]['pageToken']);
    }

    public function testAFirstPageCarriesNoToken(): void
    {
        $this->respondWith([$this->fileList([], null)]);

        $this->service()->listFolderPage();

        self::assertArrayNotHasKey('pageToken', $this->requests[0]);
    }

    /**
     * @dataProvider pageSizes
     */
    public function testPageSizeIsClampedToWhatGoogleAccepts(int $requested, int $expected): void
    {
        $this->respondWith([$this->fileList([], null)]);

        $this->service()->listFolderPage(null, null, $requested);

        self::assertSame($expected, $this->requests[0]['pageSize']);
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function pageSizes(): iterable
    {
        yield 'a sensible size passes through' => [25, 25];
        yield 'zero becomes one'               => [0, 1];
        yield 'negative becomes one'           => [-10, 1];
        yield 'above the cap is capped'        => [5000, 1000];
        yield 'exactly the cap is kept'        => [1000, 1000];
    }

    public function testListFolderStillReturnsEveryPageAtOnce(): void
    {
        $this->respondWith([
            $this->fileList([$this->file('doc-1', 'A')], 'TOKEN-2'),
            $this->fileList([$this->file('doc-2', 'B')], 'TOKEN-3'),
            $this->fileList([$this->file('doc-3', 'C')], null),
        ]);

        $items = $this->service()->listFolder();

        self::assertCount(3, $items);
        self::assertSame(['doc-1', 'doc-2', 'doc-3'], array_map(static fn (DriveDocument $d): string => $d->id, $items));
        self::assertSame(['TOKEN-2', 'TOKEN-3'], [$this->requests[1]['pageToken'], $this->requests[2]['pageToken']]);
    }

    public function testAnEndlessPaginationIsCutOffInsteadOfLoopingForever(): void
    {
        $this->files->method('listFiles')->willReturn($this->fileList([$this->file('doc', 'Doc')], 'AGAIN'));

        $this->expectException(\RuntimeException::class);

        $this->service()->listFolder();
    }

    public function testFetchingEverythingUsesTheLargestPageToSaveRoundTrips(): void
    {
        $this->respondWith([$this->fileList([], null)]);

        $this->service()->listFolder();

        self::assertSame(1000, $this->requests[0]['pageSize']);
    }

    public function testAPageMayBeEmptyWhileMoreResultsRemain(): void
    {
        // Filtering happens per item after Google answered, so a full Google page can
        // collapse to nothing while later pages still hold visible documents.
        $this->respondWith([$this->fileList([$this->file('foreign', 'Foreign')], 'TOKEN-2')]);
        $this->permissions->method('listPermissions')->willReturn($this->permissionList(['someone@example.com']));

        $page = $this->service(new FakeViewerContext('viewer@example.com'))->listFolderPage();

        self::assertSame([], $page->items);
        self::assertTrue($page->hasMore());
    }

    public function testAViewerWithoutIdentitiesGetsAnEmptyPageWithoutAskingGoogle(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        $page = $this->service(new FakeViewerContext(null))->listFolderPage();

        self::assertSame([], $page->items);
        self::assertFalse($page->hasMore());
    }

    public function testListFolderPageStillChecksAccessToTheFolder(): void
    {
        $this->files->method('get')->willReturn($this->file('folder-1', 'Folder', parents: [self::DRIVE_ID]));
        $this->permissions->method('listPermissions')->willReturn($this->permissionList([]));

        $this->expectException(\Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->listFolderPage('folder-1');
    }

    public function testSearchPagePropagatesTheQueryAndToken(): void
    {
        $this->respondWith([$this->fileList([], 'TOKEN-2')]);

        $page = $this->service()->searchPage('price list', 'TOKEN-1', 10);

        self::assertStringContainsString("name contains 'price list'", $this->requests[0]['q']);
        self::assertSame('TOKEN-1', $this->requests[0]['pageToken']);
        self::assertSame(10, $this->requests[0]['pageSize']);
        self::assertSame('TOKEN-2', $page->nextPageToken);
    }

    public function testSearchPageIsEmptyForABlankQuery(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        $page = $this->service()->searchPage('   ');

        self::assertSame([], $page->items);
        self::assertFalse($page->hasMore());
    }

    public function testListTrashPageOnlyAsksForTrashedItems(): void
    {
        $this->respondWith([$this->fileList([], 'TOKEN-2')]);

        $page = $this->service()->listTrashPage('TOKEN-1', 50);

        self::assertStringContainsString('trashed=true', $this->requests[0]['q']);
        self::assertSame('TOKEN-1', $this->requests[0]['pageToken']);
        self::assertSame(50, $this->requests[0]['pageSize']);
        self::assertTrue($page->hasMore());
    }

    public function testPageIsCountableAndIterable(): void
    {
        $page = new DrivePage([
            new DriveDocument('a', 'A', null, null, null, DriveDocument::TYPE_DOCUMENT),
            new DriveDocument('b', 'B', null, null, null, DriveDocument::TYPE_DOCUMENT),
        ], 'TOKEN');

        self::assertCount(2, $page);

        $ids = [];
        foreach ($page as $document) {
            $ids[] = $document->id;
        }

        self::assertSame(['a', 'b'], $ids);
    }

    public function testPageSerialisesForJsonResponses(): void
    {
        $page = new DrivePage([new DriveDocument('a', 'A', null, null, null, DriveDocument::TYPE_DOCUMENT)], 'TOKEN');

        self::assertSame([
            'items'         => [[
                'id'           => 'a',
                'name'         => 'A',
                'mimeType'     => null,
                'webViewLink'  => null,
                'modifiedTime' => null,
                'type'         => 'document',
                'trashed'      => false,
                'createdTime'  => null,
                'size'         => null,
                'iconLink'     => null,
                'thumbnailLink' => null,
                'lastModifiedBy' => null,
                'capabilities' => null,
            ]],
            'nextPageToken' => 'TOKEN',
            'hasMore'       => true,
        ], $page->toArray());
    }

    /**
     * @param FileList[] $responses one per expected call, in order
     */
    private function respondWith(array $responses): void
    {
        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use ($responses): FileList {
                $this->requests[] = $params;

                return $responses[count($this->requests) - 1] ?? $this->fileList([], null);
            }
        );
    }

    private function service(?ViewerContextInterface $context = null): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return new DriveDocumentService(
            $drive,
            $context ?? new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false
        );
    }

    /**
     * @param string[] $parents
     */
    private function file(string $id, string $name, array $parents = []): DriveFile
    {
        return new DriveFile([
            'id'           => $id,
            'name'         => $name,
            'mimeType'     => 'application/vnd.google-apps.spreadsheet',
            'webViewLink'  => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
            'modifiedTime' => '2026-01-01T00:00:00.000Z',
            'parents'      => $parents,
        ]);
    }

    /**
     * @param DriveFile[] $files
     */
    private function fileList(array $files, ?string $nextPageToken): FileList
    {
        $list = new FileList();
        $list->setFiles($files);

        if ($nextPageToken !== null) {
            $list->setNextPageToken($nextPageToken);
        }

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
