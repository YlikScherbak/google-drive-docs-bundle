<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Client;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use PHPUnit\Framework\TestCase;

final class GoogleClientFactoryRetryTest extends TestCase
{
    public function testTheClientRetriesTransientFailuresByDefault(): void
    {
        $client = $this->factory()->baseClient();

        self::assertSame(
            [
                'retries'       => GoogleClientFactory::DEFAULT_RETRY_ATTEMPTS,
                'initial_delay' => GoogleClientFactory::DEFAULT_INITIAL_DELAY,
                'max_delay'     => GoogleClientFactory::DEFAULT_MAX_DELAY,
            ],
            $client->getConfig('retry')
        );
    }

    public function testTheRetryMapCoversRateLimitsAndServerFaults(): void
    {
        $map = $this->factory()->baseClient()->getConfig('retry_map');

        self::assertIsArray($map);

        foreach (['429', '500', '502', '503', '504', 'rateLimitExceeded', 'userRateLimitExceeded'] as $condition) {
            self::assertArrayHasKey($condition, $map, sprintf('"%s" should be retried', $condition));
            self::assertNotSame(0, $map[$condition], sprintf('"%s" should be retried', $condition));
        }
    }

    public function testTheRetryMapNeverRetriesClientMistakes(): void
    {
        $map = $this->factory()->baseClient()->getConfig('retry_map');

        // 401/403/404 mean the request itself is wrong; repeating it only wastes quota.
        foreach (['400', '401', '403', '404'] as $condition) {
            self::assertArrayNotHasKey($condition, $map, sprintf('"%s" must not be retried', $condition));
        }
    }

    public function testRetryingCanBeTurnedOff(): void
    {
        $client = $this->factory(attempts: 0)->baseClient();

        self::assertSame([], $client->getConfig('retry'));
        self::assertNull($client->getConfig('retry_map'));
    }

    public function testDelaysAreConfigurable(): void
    {
        $client = $this->factory(attempts: 5, initialDelay: 0.25, maxDelay: 10.0)->baseClient();

        self::assertSame(
            ['retries' => 5, 'initial_delay' => 0.25, 'max_delay' => 10.0],
            $client->getConfig('retry')
        );
    }

    private function factory(
        int $attempts = GoogleClientFactory::DEFAULT_RETRY_ATTEMPTS,
        float $initialDelay = GoogleClientFactory::DEFAULT_INITIAL_DELAY,
        float $maxDelay = GoogleClientFactory::DEFAULT_MAX_DELAY
    ): GoogleClientFactory {
        return new GoogleClientFactory('client-id', 'client-secret', 'refresh-token', $attempts, $initialDelay, $maxDelay);
    }
}
