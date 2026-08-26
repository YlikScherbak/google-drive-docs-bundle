<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Exception\UploadTooLargeException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Borsche\GoogleDriveDocsBundle\Tests\UnstattableStream;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\RequestInterface;
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

    /** @var int<0, max>[] length of every chunk the upload declared, in order */
    private array $chunkLengths = [];

    /** @var RequestInterface[] every request handed to the client, in order */
    private array $sent = [];

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
        UnstattableStream::forget();

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

    public function testEveryChunkButTheLastIsAMultipleOfTheGranularity(): void
    {
        // Google takes a chunk of any size only as the last one; every earlier chunk has to be
        // a multiple of 256 KB — the same rule chunkBytes itself is validated against. A size
        // one byte past a whole number of chunks is where that is easiest to get wrong.
        $chunk = DriveDocumentService::CHUNK_GRANULARITY;
        $size  = DriveDocumentService::MULTIPART_LIMIT + 1;
        $path  = $this->tempFile('big.csv', str_repeat('x', $size));

        $this->deferredCreate();
        $this->recordChunks($size);

        $this->service(chunkBytes: $chunk)->import($path);

        $lengths = $this->chunkLengths;

        self::assertSame($size, array_sum($lengths), 'the whole file is sent, exactly once');

        $last = array_key_last($lengths);

        foreach ($lengths as $index => $length) {
            if ($index === $last) {
                continue;
            }

            self::assertSame(
                0,
                $length % DriveDocumentService::CHUNK_GRANULARITY,
                sprintf('chunk %d of %d is %d bytes, not a multiple of 256 KB', $index + 1, count($lengths), $length)
            );
        }
    }

    public function testNoChunkIsTheSingleByteGoogleWouldDrop(): void
    {
        // The client asks `false == $chunk`, and PHP reads the one-byte string "0" as false,
        // so a final chunk of exactly one byte can be dropped and the upload stall.
        $chunk = DriveDocumentService::CHUNK_GRANULARITY;
        $size  = DriveDocumentService::MULTIPART_LIMIT + 1;
        $path  = $this->tempFile('big.csv', str_repeat('x', $size - 1) . '0');

        $this->deferredCreate();
        $this->recordChunks($size);

        $this->service(chunkBytes: $chunk)->import($path);

        self::assertNotContains(1, $this->chunkLengths, 'a one-byte chunk is never sent');
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

    public function testAnApplicationCapIsHonouredWhenTheStatSaysNothing(): void
    {
        // filesize() answers 0 here, so the bytes actually read are the only authority left.
        $path = UnstattableStream::of(str_repeat('x', 2048));
        $this->files->expects(self::never())->method('create');

        $this->expectException(UploadTooLargeException::class);
        $this->expectExceptionMessageMatches('/1024/');

        $this->service(maxUploadBytes: 1024)->import($path);
    }

    public function testAnUnstattableLargeFileDeclaresItsRealSizeToGoogle(): void
    {
        // A resumable session declares the total up front and Google measures every chunk
        // against it, so a size truncated to the bounded read makes it reject the first one.
        $size = DriveDocumentService::MULTIPART_LIMIT + 100;
        $path = UnstattableStream::of(str_repeat('x', $size));

        $this->deferredCreate();
        $this->resumableExchange();

        self::assertSame('doc-1', $this->service()->import($path)->id);
        self::assertSame((string) $size, $this->sent[0]->getHeaderLine('x-upload-content-length'));
    }

    public function testAnUnstattableLargeFileStillMeetsTheApplicationCap(): void
    {
        $path = UnstattableStream::of(str_repeat('x', DriveDocumentService::MULTIPART_LIMIT + 100));

        $this->expectException(UploadTooLargeException::class);

        $this->service(maxUploadBytes: DriveDocumentService::MULTIPART_LIMIT)->import($path);
    }

    public function testAFileWhoseLastByteIsAZeroStillFinishes(): void
    {
        // The Google client asks `false == $chunk`, and PHP reads the one-byte string "0"
        // as false — left as the final chunk it would be dropped and the upload would stall
        // one byte from done. 5 MB + 1 over 256 KB chunks lands exactly there.
        $size = DriveDocumentService::MULTIPART_LIMIT + 1;
        $path = $this->tempFile('big.csv', str_repeat('x', $size - 1) . '0');

        $this->deferredCreate();
        $this->chunkedExchange($size);

        self::assertSame('doc-1', $this->service(chunkBytes: 262144)->import($path)->id);

        $chunks = array_map(
            static fn (RequestInterface $request): int => strlen((string) $request->getBody()),
            array_values(array_filter($this->sent, static fn (RequestInterface $r): bool => $r->getMethod() === 'PUT'))
        );

        self::assertNotContains(0, $chunks, 'no chunk may go out empty');
        self::assertSame($size, array_sum($chunks), 'every byte of the file is sent exactly once');
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
        yield 'beyond what fits in memory' => [DriveDocumentService::MAX_CHUNK_BYTES + DriveDocumentService::CHUNK_GRANULARITY];
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

    /**
     * Answers the session request, then every chunk, collecting the length each PUT declares
     * in its content-range header. That header is what Google measures, so it is what to look
     * at rather than anything inside the client.
     *
     */
    private function recordChunks(int $size): void
    {
        $opened = false;

        $this->client->method('execute')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$opened, $size) {
                if (!$opened) {
                    $opened = true;

                    return new HttpResponse(200, ['location' => 'https://upload.example/session']);
                }

                // "bytes FROM-TO/TOTAL"
                [$span] = explode('/', substr($request->getHeaderLine('content-range'), 6));
                [$from, $to] = array_map('intval', explode('-', $span));

                $this->chunkLengths[] = $to - $from + 1;

                if ($to + 1 >= $size) {
                    return new HttpResponse(200, ['content-type' => 'application/json'], json_encode([
                        'id'       => 'doc-1',
                        'name'     => 'big',
                        'mimeType' => 'application/vnd.google-apps.spreadsheet',
                    ]));
                }

                return new HttpResponse(308, ['range' => 'bytes 0-' . $to]);
            }
        );
    }

    /** The two exchanges a resumable upload makes: open a session, then send the bytes. */
    private function resumableExchange(): void
    {
        $calls = 0;

        $this->client->method('execute')->willReturnCallback(
            function (RequestInterface $request) use (&$calls) {
                ++$calls;
                $this->sent[] = $request;

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

    /**
     * The exchange as Google really runs it: 308 with a range header until the last chunk,
     * then the file. The single-response helper above stops after one chunk, which hides
     * anything that only goes wrong on the final one.
     */
    private function chunkedExchange(int $size): void
    {
        $received = 0;

        $this->client->method('execute')->willReturnCallback(
            function (RequestInterface $request) use (&$received, $size) {
                $this->sent[] = $request;

                if ($request->getMethod() !== 'PUT') {
                    return new HttpResponse(200, ['location' => 'https://upload.example/session']);
                }

                $received += strlen((string) $request->getBody());

                if ($received < $size) {
                    return new HttpResponse(308, ['range' => 'bytes=0-' . ($received - 1)]);
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
