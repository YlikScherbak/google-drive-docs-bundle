<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('', $config['client_id']);
        self::assertSame('', $config['shared_drive_id']);
        self::assertSame([Configuration::MIME_SPREADSHEET], $config['document_mime_types']);
        self::assertFalse($config['notify_on_share']);
    }

    public function testMimeTypesCanBeOverridden(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'document_mime_types' => [Configuration::MIME_DOCUMENT, Configuration::MIME_PRESENTATION],
            'notify_on_share'     => true,
        ]]);

        self::assertSame([Configuration::MIME_DOCUMENT, Configuration::MIME_PRESENTATION], $config['document_mime_types']);
        self::assertTrue($config['notify_on_share']);
    }
}
