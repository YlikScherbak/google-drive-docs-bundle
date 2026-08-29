<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Controller\DriveDocumentResolver;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\GoogleDriveDocsExtension;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class GoogleDriveDocsExtensionTest extends TestCase
{
    public function testItRegistersTheServices(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([[
            'client_id'       => 'id',
            'client_secret'   => 'secret',
            'refresh_token'   => 'token',
            'shared_drive_id' => 'drive',
        ]], $container);

        self::assertTrue($container->hasDefinition(GoogleClientFactory::class));
        self::assertTrue($container->hasDefinition(DriveDocumentService::class));
        self::assertTrue($container->hasDefinition('google_drive_docs.drive'));

        // The factory that builds the client asks Google for a token, so deferring it is what
        // keeps a request that never touches Drive from paying for one. Asserted on the
        // definition rather than the instance: a ContainerBuilder resolves lazy services eagerly,
        // and only a dumped container hands back the proxy.
        self::assertTrue(
            $container->getDefinition('google_drive_docs.client')->isLazy(),
            'a request that does not touch Drive would pay for an OAuth round trip'
        );

        // FrameworkBundle registers RequestAttributeValueResolver at 100 and is normally first, so
        // a tie hands #[Route('/d/{document}')] to it and the controller receives the raw string.
        $tag = $container->getDefinition(DriveDocumentResolver::class)
            ->getTag('controller.argument_value_resolver')[0] ?? [];

        self::assertGreaterThan(
            100,
            $tag['priority'] ?? 0,
            'a route parameter named after the argument would resolve to a string'
        );

        // And the sharing memo has to be cleared between requests in a worker runtime.
        self::assertSame(
            [['method' => 'reset']],
            $container->getDefinition(DriveDocumentService::class)->getTag('kernel.reset')
        );
        self::assertTrue($container->hasAlias(ViewerContextInterface::class));
        self::assertTrue($container->hasAlias('google_drive_docs.service'));
    }

    public function testConfigurationReachesTheService(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([[
            'shared_drive_id'     => 'my-drive',
            'document_mime_types' => ['application/vnd.google-apps.document'],
            'notify_on_share'     => true,
        ]], $container);

        $arguments = $container->getDefinition(DriveDocumentService::class)->getArguments();

        self::assertSame('my-drive', $arguments[2]);
        self::assertSame(['application/vnd.google-apps.document'], $arguments[3]);
        self::assertTrue($arguments[4]);
    }

    public function testItRegistersTheSpreadsheetService(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);

        self::assertTrue($container->hasDefinition(SpreadsheetService::class));
        self::assertTrue($container->hasDefinition('google_drive_docs.sheets'));
        self::assertTrue($container->hasAlias('google_drive_docs.spreadsheets'));
        // Reachable without autowiring, like DriveDocumentService.
        self::assertTrue($container->getDefinition(SpreadsheetService::class)->isPublic());
        self::assertTrue($container->getAlias('google_drive_docs.spreadsheets')->isPublic());
        self::assertFalse($container->getDefinition('google_drive_docs.sheets')->isPublic());

        $arguments = $container->getDefinition(SpreadsheetService::class)->getArguments();

        // Same authenticated client as Drive, so retries and backoff carry over.
        self::assertSame('google_drive_docs.sheets', (string) $arguments[0]);
        self::assertSame(DriveDocumentService::class, (string) $arguments[1]);
    }

    public function testUploadSettingsReachTheService(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([[
            'upload' => ['max_bytes' => 104857600, 'chunk_bytes' => 524288],
        ]], $container);

        $arguments = $container->getDefinition(DriveDocumentService::class)->getArguments();

        self::assertSame(104857600, $arguments[8]);
        self::assertSame(524288, $arguments[9]);
    }

    public function testRetrySettingsReachTheClientFactory(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([[
            'retry' => ['attempts' => 5, 'initial_delay' => 0.25, 'max_delay' => 10.0],
        ]], $container);

        $arguments = $container->getDefinition(GoogleClientFactory::class)->getArguments();

        self::assertSame(5, $arguments[3]);
        self::assertSame(0.25, $arguments[4]);
        self::assertSame(10.0, $arguments[5]);
    }

    /**
     * The logger has to be wired here, because an application cannot wire it itself.
     *
     * README used to say to pass it in services.yaml under the service's own id. That does not
     * amend this definition, it replaces it: ten arguments become one, and the build then dies
     * autowiring $drive, which is registered as google_drive_docs.drive and not under its class.
     * So the one thing separating "hidden because the lookup failed" from "not shared with you"
     * was unreachable from a Symfony application altogether.
     */
    public function testTheLoggerIsWiredWithNoConfigurationAtAll(): void
    {
        $container = new ContainerBuilder();

        // A definition, not an object set on the container: only a definition survives
        // ResolveInvalidReferencesPass, and an application registers its logger as one.
        $container->setDefinition('logger', (new Definition(NullLogger::class))->setPublic(true));

        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);
        $container->compile();

        $service = $container->get('google_drive_docs.service');
        self::assertInstanceOf(DriveDocumentService::class, $service);

        // Asserted on the built service rather than on the definition: a definition can hold a
        // reference that never arrives, which is the shape of the bug this replaces.
        self::assertSame($container->get('logger'), self::loggerOf($service));
    }

    /** And an application with no logger at all still boots, like the dispatcher above it. */
    public function testTheServiceBootsWithoutALogger(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);
        $container->compile();

        $service = $container->get('google_drive_docs.service');
        self::assertInstanceOf(DriveDocumentService::class, $service);
        self::assertNull(self::loggerOf($service));
    }

    /**
     * Its own Monolog channel, which is what wanting "your own logger" usually means: a level and
     * a handler set in monolog.yaml, rather than a service id passed to this bundle.
     */
    public function testTheLoggerGoesToItsOwnChannel(): void
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);

        self::assertSame(
            [['channel' => 'google_drive_docs']],
            $container->getDefinition(DriveDocumentService::class)->getTag('monolog.logger')
        );
    }

    private static function loggerOf(DriveDocumentService $service): ?LoggerInterface
    {
        $logger = (new \ReflectionProperty(DriveDocumentService::class, 'logger'))->getValue($service);
        self::assertTrue($logger === null || $logger instanceof LoggerInterface);

        return $logger;
    }
}
