<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
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

        $treeBuilder->getRootNode()
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
                ->booleanNode('notify_on_share')
                    ->defaultFalse()
                    ->info('Send Google notification e-mails when granting access.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
