<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Exception\UploadTooLargeException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DriveDocumentServiceResumableTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;
    private Client&MockObject $client;
    private CollectingEventDispatcher $dispatcher;

    /** @var string[] */
    private array $tempDirs = [];

    /** @var bool[] recorded setDefer() calls, in order */
    private array $defer = [];

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->client      = $this->createMock(Client::class);
        $this->dispatcher  = new CollectingEventDispatcher();

        $this->client->method('setDefer')->willReturnCallback(
            function (bool $defer): void {
                $this->defer[] = $defer;
            }
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->tempDirs = [];
    }

    public function testASmallFileStillGoesUpInOneRequest(): void
    {
        $path = $this->tempFile('small.csv', 'a,b,c');

        $params = null;
        $this->files->method('create')->willReturnCallback(
            function (DriveFile $file, array $options) use (&$params): DriveFile {
                $params = $options;

                return $this->created();
            }
        );

        $this->service()->import($path);

        self::assertSame('multipart', $params['uploadType']);
        self::assertSame([], $this->defer, 'a small upload never touches the client state');
    }

    public function testALargeFileSwitchesToTheResumableProtocol(): void
    {
        $path = $this->tempFile('big.csv', str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 1));

        $this->deferredCreate();
        $this->resumableExchange();

        $document = $this->service()->import($path);

        self::assertSame('doc-1', $document->id);
        self::assertSame([true, false], $this->defer, 'the deferred flag is set and put back');
    }

    public function testTheClientIsNotLeftDeferredWhenTheUploadFails(): void
    {
        // setDefer(true) is global on the client: leaving it on would turn every later call
        // into a Request object instead of a result, far away from here.
        $path = $this->tempFile('big.csv', str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 1));

        $this->deferredCreate();
        $this->client->method('execute')->willThrowException(new \RuntimeException('network gone'));

        try {
            $this->service()->import($path);
            self::fail('Expected the failure to surface.');
        } catch (\RuntimeException $e) {
            self::assertSame('network gone', $e->getMessage());
        }

        self::assertSame([true, false], $this->defer);
    }

    public function testALargeImportStillReportsTheEvent(): void
    {
        $path = $this->tempFile('big.csv', str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 1));

        $this->deferredCreate();
        $this->resumableExchange();

        $this->service()->import($path);

        $event = $this->dispatcher->single(\Borsche\GoogleDriveDocsBundle\Event\DocumentImportedEvent::class);
        self::assertSame('big.csv', $event->originalFilename);
    }

    public function testAClientThatDidNotDeferIsReportedClearly(): void
    {
        // If the deferred flag were ever not in effect, files->create() would run and answer
        // with a DriveFile. Saying so here beats MediaFileUpload failing further away.
        $path = $this->tempFile('big.csv', str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 1));

        $this->files->method('create')->willReturn($this->created());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/defer/');

        try {
            $this->service()->import($path);
        } finally {
            self::assertSame([true, false], $this->defer);
        }
    }

    public function testAnApplicationCapIsHonouredBeforeAnythingIsRead(): void
    {
        $path = $this->tempFile('big.csv', str_repeat('x', 2048));
        $this->files->expects(self::never())->method('create');

        $this->expectException(UploadTooLargeException::class);
        $this->expectExceptionMessageMatches('/1024/');

        $this->service(maxUploadBytes: 1024)->import($path);
    }

    public function testNoCapMeansNoCeiling(): void
    {
        $path = $this->tempFile('big.csv', str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 1));

        $this->deferredCreate();
        $this->resumableExchange();

        // maxUploadBytes 0 is the default: Drive's own 5 TB is the only limit left.
        self::assertSame('doc-1', $this->service()->import($path)->id);
    }

    /**
     * @dataProvider badChunkSizes
     */
    public function testTheChunkSizeMustSuitTheProtocol(int $chunk): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(chunkBytes: $chunk);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function badChunkSizes(): iterable
    {
        yield 'zero'                  => [0];
        yield 'negative'              => [-262144];
        yield 'not a 256 KB multiple' => [300000];
    }

    private function created(): DriveFile
    {
        return new DriveFile([
            'id'       => 'doc-1',
            'name'     => 'big',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ]);
    }

    /**
     * With setDefer(true) the Google client hands back the request instead of running it.
     *
     * The X-Php-Expected-Class header is what the real client puts there, and what tells it
     * which class to decode the final response into — without it the upload would come back
     * as a raw HTTP response.
     */
    private function deferredCreate(): void
    {
        $this->files->method('create')->willReturn(
            new HttpRequest(
                'POST',
                'https://www.googleapis.com/upload/drive/v3/files',
                ['X-Php-Expected-Class' => DriveFile::class],
                '{}'
            )
        );
    }

    /** The two exchanges a resumable upload makes: open a session, then send the bytes. */
    private function resumableExchange(): void
    {
        $calls = 0;

        $this->client->method('execute')->willReturnCallback(
            function () use (&$calls) {
                ++$calls;

                if ($calls === 1) {
                    // Google answers the session request with the URI to PUT the chunks to.
                    return new HttpResponse(200, ['location' => 'https://upload.example/session']);
                }

                return new HttpResponse(200, ['content-type' => 'application/json'], json_encode([
                    'id'       => 'doc-1',
                    'name'     => 'big',
                    'mimeType' => 'application/vnd.google-apps.spreadsheet',
                ]));
            }
        );
    }

    private function tempFile(string $name, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/gddb-' . uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;

        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function service(int $maxUploadBytes = 0, int $chunkBytes = 8388608): DriveDocumentService
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;
        $drive->method('getClient')->willReturn($this->client);

        return new DriveDocumentService(
            $drive,
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false,
            $this->dispatcher,
            null,
            300,
            $maxUploadBytes,
            $chunkBytes
        );
    }
}
