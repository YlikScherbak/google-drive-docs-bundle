<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Command\AuthorizeCommand;
use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler\ValidateCachePoolPass;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class GoogleDriveDocsExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // OAuth client factory
        $factory = new Definition(GoogleClientFactory::class, [
            $config['client_id'],
            $config['client_secret'],
            $config['refresh_token'],
            $config['retry']['attempts'],
            $config['retry']['initial_delay'],
            $config['retry']['max_delay'],
        ]);
        $factory->setPublic(false);
        $container->setDefinition(GoogleClientFactory::class, $factory);

        // Google\Client built by the factory (already authenticated)
        $client = new Definition(Client::class);
        $client->setFactory([new Reference(GoogleClientFactory::class), 'create']);
        $client->setPublic(false);
        $container->setDefinition('google_drive_docs.client', $client);

        // Drive service
        $drive = new Definition(Drive::class, [new Reference('google_drive_docs.client')]);
        $drive->setPublic(false);
        $container->setDefinition('google_drive_docs.drive', $drive);

        // Default viewer context: everyone sees everything (override in your app)
        $container->setDefinition(AllowAllViewerContext::class, (new Definition(AllowAllViewerContext::class))->setPublic(false));
        $container->setAlias(ViewerContextInterface::class, AllowAllViewerContext::class);

        // Main service
        $service = new Definition(DriveDocumentService::class, [
            new Reference('google_drive_docs.drive'),
            new Reference(ViewerContextInterface::class),
            $config['shared_drive_id'],
            $config['document_mime_types'],
            $config['notify_on_share'],
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['permission_cache']['pool'] !== null
                ? new Reference($config['permission_cache']['pool'], ContainerInterface::NULL_ON_INVALID_REFERENCE)
                : null,
            $config['permission_cache']['ttl'],
            $config['upload']['max_bytes'],
            $config['upload']['chunk_bytes'],
        ]);

        // Checked after every bundle registered its services (see ValidateCachePoolPass).
        $container->setParameter(ValidateCachePoolPass::POOL_PARAMETER, $config['permission_cache']['pool']);
        $service->setPublic(true);
        $container->setDefinition(DriveDocumentService::class, $service);
        $container->setAlias('google_drive_docs.service', DriveDocumentService::class)->setPublic(true);

        // Sheets service, on the same client so retries and backoff carry over
        $sheets = new Definition(Sheets::class, [new Reference('google_drive_docs.client')]);
        $sheets->setPublic(false);
        $container->setDefinition('google_drive_docs.sheets', $sheets);

        // Spreadsheet contents. Access decisions stay with DriveDocumentService.
        $spreadsheets = new Definition(SpreadsheetService::class, [
            new Reference('google_drive_docs.sheets'),
            new Reference(DriveDocumentService::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $spreadsheets->setPublic(true);
        $container->setDefinition(SpreadsheetService::class, $spreadsheets);
        $container->setAlias('google_drive_docs.spreadsheets', SpreadsheetService::class)->setPublic(true);

        // Console command (optional)
        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $command = new Definition(AuthorizeCommand::class, [new Reference(GoogleClientFactory::class)]);
            $command->addTag('console.command');
            $container->setDefinition(AuthorizeCommand::class, $command);
        }
    }

    public function getAlias(): string
    {
        return 'google_drive_docs';
    }
}
