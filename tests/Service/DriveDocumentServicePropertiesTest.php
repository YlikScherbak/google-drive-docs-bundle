<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\DocumentPropertiesChangedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServicePropertiesTest extends TestCase
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

    // ------------------------------------------------------------- reading

    public function testAppPropertiesAreRead(): void
    {
        $captured = null;
        $this->files->method('get')->willReturnCallback(
            function (string $id, array $params) use (&$captured): DriveFile {
                $captured = $params['fields'];

                return new DriveFile(['id' => 'doc', 'appProperties' => ['orderId' => '4711']]);
            }
        );

        self::assertSame(['orderId' => '4711'], $this->service()->appProperties('doc'));
        self::assertStringContainsString('appProperties', $captured);
    }

    public function testAFileWithoutPropertiesReadsAsAnEmptyArray(): void
    {
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc']));

        self::assertSame([], $this->service()->appProperties('doc'));
    }

    public function testReadingPropertiesRequiresAccess(): void
    {
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]));
        $this->permissions->method('listPermissions')->willReturn(new PermissionList());

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->appProperties('doc');
    }

    // ------------------------------------------------------------- writing

    public function testSetAppPropertiesSendsThemAsAMerge(): void
    {
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return new DriveFile(['id' => 'doc', 'appProperties' => ['orderId' => '4711']]);
            }
        );

        $this->service()->setAppProperties('doc', ['orderId' => '4711']);

        self::assertSame(['orderId' => '4711'], $payload->getAppProperties());
    }

    public function testANullValueRemovesTheKey(): void
    {
        // Drive deletes a property when its value arrives as null — and this assertion used to
        // read the getter on the object it had just written to, which agreed while the request
        // body did not. The body is the only thing Drive sees.
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return new DriveFile(['id' => 'doc']);
            }
        );

        $this->service()->setAppProperties('doc', ['orderId' => null]);

        $wire = json_decode(
            json_encode($payload->toSimpleObject(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(['orderId' => null], $wire['appProperties']);
    }

    public function testNumbersAreSentAsStringsBecauseThatIsAllDriveStores(): void
    {
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $id, DriveFile $file) use (&$payload): DriveFile {
                $payload = $file;

                return new DriveFile(['id' => 'doc']);
            }
        );

        $this->service()->setAppProperties('doc', ['orderId' => 4711, 'paid' => true]);

        self::assertSame(['orderId' => '4711', 'paid' => '1'], $payload->getAppProperties());
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $this->files->expects(self::never())->method('update');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->setAppProperties('doc', ['' => 'x']);
    }

    public function testWritingNothingAsksGoogleNothing(): void
    {
        $this->files->expects(self::never())->method('update');

        $this->service()->setAppProperties('doc', []);

        self::assertSame([], $this->dispatcher->events);
    }

    public function testWritingPropertiesRequiresAccess(): void
    {
        $this->files->method('get')->willReturn(new DriveFile(['id' => 'doc', 'parents' => [self::DRIVE_ID]]));
        $this->permissions->method('listPermissions')->willReturn(new PermissionList());
        $this->files->expects(self::never())->method('update');

        $this->expectException(AccessDeniedException::class);

        $this->service(new FakeViewerContext('viewer@example.com'))->setAppProperties('doc', ['a' => 'b']);
    }

    public function testSettingPropertiesReportsWhatChanged(): void
    {
        $this->files->method('update')->willReturn(new DriveFile(['id' => 'doc']));

        $this->service()->setAppProperties('doc', ['orderId' => '4711']);

        $event = $this->dispatcher->single(DocumentPropertiesChangedEvent::class);
        self::assertSame('doc', $event->fileId);
        self::assertSame(['orderId' => '4711'], $event->properties);
    }

    // ------------------------------------------------------------ searching

    public function testFindByAppPropertyBuildsTheQuery(): void
    {
        $captured = null;
        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params['q'];

                return new FileList();
            }
        );

        $this->service()->findByAppProperty('orderId', '4711');

        self::assertStringContainsString("appProperties has { key='orderId' and value='4711' }", $captured);
        self::assertStringContainsString('trashed=false', $captured);
    }

    public function testFindByAppPropertyEscapesBothHalves(): void
    {
        // Key and value are interpolated into the query, so a quote must not end it early.
        $captured = null;
        $this->files->method('listFiles')->willReturnCallback(
            function (array $params) use (&$captured): FileList {
                $captured = $params['q'];

                return new FileList();
            }
        );

        $this->service()->findByAppProperty("or'der", "O'Brien");

        self::assertStringContainsString("key='or\\'der'", $captured);
        self::assertStringContainsString("value='O\\'Brien'", $captured);
    }

    public function testFindByAppPropertyRefusesAnEmptyKey(): void
    {
        $this->files->expects(self::never())->method('listFiles');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->findByAppProperty('', '4711');
    }

    public function testFindByAppPropertyReturnsTheMatches(): void
    {
        $list = new FileList();
        $list->setFiles([
            new DriveFile([
                'id'       => 'doc-1',
                'name'     => 'Invoice',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ]),
        ]);
        $this->files->method('listFiles')->willReturn($list);

        $found = $this->service()->findByAppProperty('orderId', '4711');

        self::assertCount(1, $found);
        self::assertSame('doc-1', $found[0]->id);
    }

    public function testFindByAppPropertyPageIsPaginated(): void
    {
        $list = new FileList();
        $list->setFiles([]);
        $list->setNextPageToken('TOKEN-2');
        $this->files->method('listFiles')->willReturn($list);

        $page = $this->service()->findByAppPropertyPage('orderId', '4711');

        self::assertTrue($page->hasMore());
        self::assertSame('TOKEN-2', $page->nextPageToken);
    }

    public function testRemovingAPropertySendsAJsonNullToDrive(): void
    {
        // The docblock promises that a null value removes the key, and the getter agreed while the
        // body did not: the client drops a plain null when it serialises, so the key stayed on the
        // file and the removal was silent. Asserting the getter is what let that through — the
        // same trap that made a wrong setExpiry() fix look correct in 1.0.2.
        $payload = null;
        $this->files->method('update')->willReturnCallback(
            function (string $fileId, DriveFile $body) use (&$payload): DriveFile {
                $payload = $body;

                return new DriveFile(['id' => $fileId]);
            }
        );

        $this->service()->setAppProperties('doc-1', ['orderId' => '4711', 'obsolete' => null]);

        $wire = json_decode(
            json_encode($payload->toSimpleObject(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayHasKey(
            'obsolete',
            $wire['appProperties'],
            'the key never left the process, so Drive was never asked to remove it'
        );
        self::assertNull($wire['appProperties']['obsolete']);
        self::assertSame('4711', $wire['appProperties']['orderId']);
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
            false,
            $this->dispatcher
        );
    }
}
