<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Client;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use PHPUnit\Framework\TestCase;

final class GoogleClientFactoryTest extends TestCase
{
    public function testMissingCredentialsAreReported(): void
    {
        $this->expectException(NotConfiguredException::class);

        (new GoogleClientFactory('', '', 'refresh-token'))->baseClient();
    }

    public function testMissingRefreshTokenIsReported(): void
    {
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/authorize/');

        (new GoogleClientFactory('client-id', 'client-secret', ''))->create();
    }

    public function testBaseClientIsConfiguredForOfflineAccess(): void
    {
        $client = (new GoogleClientFactory('client-id', 'client-secret', 'refresh-token'))->baseClient();

        self::assertSame('client-id', $client->getClientId());
        self::assertSame(GoogleClientFactory::SCOPES, $client->getScopes());
    }
}
