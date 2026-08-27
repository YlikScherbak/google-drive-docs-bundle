<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const MIME_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';
    public const MIME_DOCUMENT    = 'application/vnd.google-apps.document';
    public const MIME_PRESENTATION = 'application/vnd.google-apps.presentation';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('google_drive_docs');

        $root = $treeBuilder->getRootNode();
        // symfony/config 6.4 annotates getRootNode() as the narrower NodeDefinition, so analysis
        // against the oldest versions this package allows reports children() as undefined while the
        // runtime is perfectly happy. The assertion is compiled out in production.
        //
        // The rest of the chain has the same problem and cannot be fixed the same way: on 6.4 each
        // ->end() is annotated as returning NodeParentInterface, so every node after the first is
        // unresolvable. That one is ignored in phpstan.neon.dist rather than worked around here —
        // restructuring a fluent builder to satisfy an old annotation set would make this file
        // worse to read for no gain at runtime.
        \assert($root instanceof ArrayNodeDefinition);

        $root
            ->children()
                ->scalarNode('client_id')
                    ->defaultValue('')
                    ->info('OAuth client ID (Desktop app) from Google Cloud Console.')
                ->end()
                ->scalarNode('client_secret')
                    ->defaultValue('')
                    ->info('OAuth client secret. Keep it out of version control.')
                ->end()
                ->scalarNode('refresh_token')
                    ->defaultValue('')
                    ->info('Long-lived refresh token obtained via google-drive-docs:authorize.')
                ->end()
                ->scalarNode('shared_drive_id')
                    ->defaultValue('')
                    ->info('ID of the Shared Drive that stores the documents.')
                ->end()
                ->arrayNode('document_mime_types')
                    ->scalarPrototype()->end()
                    ->defaultValue([self::MIME_SPREADSHEET])
                    ->info('Google MIME types treated as documents. Folders are always included.')
                ->end()
                ->arrayNode('permission_cache')
                    ->info('Caches the sharing lookups used by visibility filtering.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultNull()
                            ->info('PSR-6 cache pool service id, e.g. "cache.app". Null disables caching.')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(300)
                            ->min(0)
                            ->info('Lifetime in seconds. Keep it short: sharing may also change directly in Google. 0 keeps lookups out of the pool (per-request caching only).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('upload')
                    ->info('Limits and chunking for import().')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_bytes')
                            ->defaultValue(0)
                            ->min(0)
                            ->info('A ceiling of your own in bytes. 0 leaves Drive\'s own 5 TB as the only one.')
                        ->end()
                        ->integerNode('chunk_bytes')
                            ->defaultValue(8 * 1024 * 1024)
                            ->min(DriveDocumentService::CHUNK_GRANULARITY)
                            ->max(DriveDocumentService::MAX_CHUNK_BYTES)
                            ->info('Bytes per resumable chunk; must be a multiple of 256 KB.')
                            // The service constructor insists on this too, but it only runs on
                            // the first import; a container build is where a typo should stop.
                            ->validate()
                                ->ifTrue(static fn (int $bytes): bool => $bytes % DriveDocumentService::CHUNK_GRANULARITY !== 0)
                                ->thenInvalid(sprintf(
                                    'A resumable chunk must be a multiple of %d bytes, %%s given.',
                                    DriveDocumentService::CHUNK_GRANULARITY
                                ))
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('retry')
                    ->info('Exponential backoff for rate limits and transient Google faults.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('attempts')
                            ->defaultValue(GoogleClientFactory::DEFAULT_RETRY_ATTEMPTS)
                            ->min(0)
                            ->info('Extra tries after the first failure. 0 disables retrying.')
                        ->end()
                        ->floatNode('initial_delay')
                            ->defaultValue(GoogleClientFactory::DEFAULT_INITIAL_DELAY)
                            ->min(0)
                            ->info('Seconds to wait before the first retry; doubles on each further one.')
                        ->end()
                        ->floatNode('max_delay')
                            ->defaultValue(GoogleClientFactory::DEFAULT_MAX_DELAY)
                            ->min(0.001)
                            ->info('Upper bound in seconds for a single wait.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http')
                    ->info('Limits on the HTTP calls to Google. Both default to a limit rather than none.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('timeout')
                            ->defaultValue(GoogleClientFactory::DEFAULT_TIMEOUT)
                            ->min(0)
                            ->info('Seconds for a whole request. 0 waits for ever, which is Guzzle\'s own default.')
                        ->end()
                        ->floatNode('connect_timeout')
                            ->defaultValue(GoogleClientFactory::DEFAULT_CONNECT_TIMEOUT)
                            ->min(0)
                            ->info('Seconds to get the connection open. 0 waits for ever.')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('notify_on_share')
                    ->defaultFalse()
                    ->info('Send Google notification e-mails when granting access.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
