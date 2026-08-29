<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\DependencyInjection\GoogleDriveDocsExtension;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\LoggerChannelPass;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The `monolog.logger` tag against the bundle that has to act on it.
 *
 * Everything else about the logger is asserted with no Monolog installed, which proves the tag is
 * present and harmless but not that it does anything. What makes the channel worth having is
 * LoggerChannelPass swapping the reference for a channel of ours — and that is somebody else's
 * code, on a dependency the bundle does not require. A release has already gone out broken on an
 * assumption about a dependency's behaviour that was never executed, so this executes it.
 */
final class MonologChannelTest extends TestCase
{
    public function testTheLoggerArrivesOnTheBundlesOwnChannel(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');

        (new MonologExtension())->load(
            [['handlers' => ['main' => ['type' => 'null', 'level' => 'debug']]]],
            $container
        );

        // MonologBundle::build() registers this; a container assembled by hand does it by hand.
        $container->addCompilerPass(new LoggerChannelPass());

        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);
        $container->compile();

        $service = $container->get('google_drive_docs.service');
        self::assertInstanceOf(DriveDocumentService::class, $service);

        $logger = (new \ReflectionProperty(DriveDocumentService::class, 'logger'))->getValue($service);

        // Not merely a logger: the channel is the whole point, because it is what lets an
        // application set a level and a handler for these lines without touching everything else.
        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('google_drive_docs', $logger->getName());
    }
}
