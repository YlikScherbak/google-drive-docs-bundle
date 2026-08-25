<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        self::assertSame(GoogleClientFactory::DEFAULT_RETRY_ATTEMPTS, $config['retry']['attempts']);
        self::assertSame(GoogleClientFactory::DEFAULT_INITIAL_DELAY, $config['retry']['initial_delay']);
        self::assertSame(GoogleClientFactory::DEFAULT_MAX_DELAY, $config['retry']['max_delay']);
        self::assertSame(0, $config['upload']['max_bytes']);
        self::assertSame(8 * 1024 * 1024, $config['upload']['chunk_bytes']);
    }

    public function testRetryCanBeTunedAndDisabled(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'retry' => ['attempts' => 0, 'initial_delay' => 0.5, 'max_delay' => 5.0],
        ]]);

        self::assertSame(0, $config['retry']['attempts']);
        self::assertSame(0.5, $config['retry']['initial_delay']);
        self::assertSame(5.0, $config['retry']['max_delay']);
    }

    public function testUploadLimitsCanBeTuned(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'upload' => ['max_bytes' => 104857600, 'chunk_bytes' => 262144],
        ]]);

        self::assertSame(104857600, $config['upload']['max_bytes']);
        self::assertSame(262144, $config['upload']['chunk_bytes']);
    }

    public function testAChunkSmallerThanGooglesGranularityIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['upload' => ['chunk_bytes' => 1024]]]);
    }

    public function testNegativeRetryAttemptsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['retry' => ['attempts' => -1]]]);
    }

    public function testAZeroMaxDelayIsRejected(): void
    {
        // Google's task runner throws on a non-positive max_delay; catch it in config instead.
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['retry' => ['max_delay' => 0]]]);
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
