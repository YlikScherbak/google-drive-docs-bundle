<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Client;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

    public function testARevokedRefreshTokenIsReportedAsAConfigurationProblem(): void
    {
        $factory = new class ('client-id', 'client-secret', 'stale-token') extends GoogleClientFactory {
            public function baseClient(): Client
            {
                $client = parent::baseClient();
                // Google's answer once the service user revoked the app or the token aged out.
                $client->setHttpClient(new GuzzleClient([
                    'handler'     => HandlerStack::create(new MockHandler([
                        new Response(400, ['Content-Type' => 'application/json'], json_encode([
                            'error'             => 'invalid_grant',
                            'error_description' => 'Token has been expired or revoked.',
                        ], JSON_THROW_ON_ERROR)),
                    ])),
                    'http_errors' => false,
                ]));

                return $client;
            }
        };

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/expired or revoked/');

        $factory->create();
    }

    public function testBaseClientIsConfiguredForOfflineAccess(): void
    {
        $client = (new GoogleClientFactory('client-id', 'client-secret', 'refresh-token'))->baseClient();

        self::assertSame('client-id', $client->getClientId());
        self::assertSame(GoogleClientFactory::SCOPES, $client->getScopes());
    }
}
