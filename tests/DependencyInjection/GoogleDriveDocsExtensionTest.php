<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\GoogleDriveDocsExtension;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
}
