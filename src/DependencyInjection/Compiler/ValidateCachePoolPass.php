<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Warns when the configured cache pool does not exist.
 *
 * A missing pool only disables caching, so failing the build would be harsh — but
 * silently ignoring a typo would hide a real performance problem. Hence a warning:
 * visible in the compiler log and in the error log, without breaking the application.
 */
final class ValidateCachePoolPass implements CompilerPassInterface
{
    public const POOL_PARAMETER = 'google_drive_docs.permission_cache.pool';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::POOL_PARAMETER)) {
            return;
        }

        $pool = $container->getParameter(self::POOL_PARAMETER);

        if (!is_string($pool) || $pool === '' || $container->has($pool)) {
            return;
        }

        $message = sprintf(
            'google_drive_docs.permission_cache.pool points to "%s", which is not a registered service. '
            . 'Sharing lookups will not be cached.',
            $pool
        );

        $container->log($this, $message);
        @trigger_error($message, \E_USER_WARNING);
    }
}
