<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Client;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Client\RetryOnConnectionFailure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * The limits on the calls to Google, and the retry the SDK does not do.
 *
 * Guzzle's own default is to wait for ever, and the retry map the bundle configures never covered
 * connection failures: the task runner catches `Google\Service\Exception` alone, so a connection
 * that never opened was rethrown untouched. Both were documented as handled.
 */
final class GoogleClientHttpTest extends TestCase
{
    public function testTheHttpClientCarriesLimitsAndKeepsTheSdkDefaults(): void
    {
        $client = (new GoogleClientFactory('id', 'secret', 'refresh'))->baseClient();
        $http   = $client->getHttpClient();

        self::assertInstanceOf(GuzzleClient::class, $http);
        self::assertSame(GoogleClientFactory::DEFAULT_TIMEOUT, $http->getConfig('timeout'));
        self::assertSame(GoogleClientFactory::DEFAULT_CONNECT_TIMEOUT, $http->getConfig('connect_timeout'));

        // The two the Google client sets when it builds its own, and the second is load-bearing:
        // the REST layer reads the status off the response instead of catching an exception.
        self::assertFalse($http->getConfig('http_errors'));
        self::assertSame('https://www.googleapis.com', (string) $http->getConfig('base_uri'));
    }

    public function testTheLimitsCanBeTurnedOff(): void
    {
        // Zero means Guzzle's own behaviour, for anyone who wants it back.
        $http = (new GoogleClientFactory('id', 'secret', 'refresh', 3, 1.0, 60.0, 0.0, 0.0))
            ->baseClient()
            ->getHttpClient();

        self::assertNull($http->getConfig('timeout'));
        self::assertNull($http->getConfig('connect_timeout'));
    }

    public function testAConnectionFailureIsRetried(): void
    {
        $request = new Request('GET', 'https://www.googleapis.com/drive/v3/files');

        $mock = new MockHandler([
            new ConnectException('Could not resolve host', $request),
            new ConnectException('Could not resolve host', $request),
            new Response(200, [], '{}'),
        ]);

        $stack = HandlerStack::create($mock);
        // No delay in the test: the schedule is arithmetic, and sleeping for it proves nothing.
        $stack->push(RetryOnConnectionFailure::middleware(3, 0.0, 0.0));

        $response = (new GuzzleClient(['handler' => $stack]))->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $mock, 'all three queued outcomes should have been consumed');
    }

    public function testItGivesUpAfterTheConfiguredNumberOfAttempts(): void
    {
        $request = new Request('GET', 'https://www.googleapis.com/drive/v3/files');

        $mock  = new MockHandler(array_fill(0, 5, new ConnectException('Connection refused', $request)));
        $stack = HandlerStack::create($mock);
        $stack->push(RetryOnConnectionFailure::middleware(2, 0.0, 0.0));

        $this->expectException(ConnectException::class);

        try {
            (new GuzzleClient(['handler' => $stack]))->send($request);
        } finally {
            // One attempt plus two retries: the rest of the queue is untouched.
            self::assertCount(2, $mock);
        }
    }

    public function testAResponseIsLeftToTheTaskRunner(): void
    {
        // A 429 or a 503 comes back as a response rather than an exception, because http_errors is
        // off. Retrying it here as well would stack two ladders on the same failure.
        $request = new Request('GET', 'https://www.googleapis.com/drive/v3/files');

        $mock  = new MockHandler([new Response(503, [], '{}'), new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(RetryOnConnectionFailure::middleware(3, 0.0, 0.0));

        $response = (new GuzzleClient(['handler' => $stack, 'http_errors' => false]))->send($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $mock, 'the 503 must not have been retried here');
    }

    public function testRetryingCanBeDisabledEntirely(): void
    {
        $client = (new GoogleClientFactory('id', 'secret', 'refresh', 0))->baseClient();

        // Nothing to assert about the stack from outside, so assert the config the runner reads.
        self::assertNull($client->getConfig('retry_map'));
        self::assertSame([], $client->getConfig('retry'));
    }
}
