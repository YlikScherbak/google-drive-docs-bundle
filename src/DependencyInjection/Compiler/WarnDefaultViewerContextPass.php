<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Notes in the compiler log when the application still runs on the default viewer context.
 *
 * With AllowAllViewerContext every user sees, edits and shares the whole drive. That is the
 * right setting for a single-tenant back office or a CLI, so it is not an error and not
 * even a runtime warning — but it must never be an accident, hence the trace in the log
 * (visible via `debug:container --deprecations`-style tooling and in the compiler output).
 */
final class WarnDefaultViewerContextPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(ViewerContextInterface::class)) {
            return;
        }

        if ((string) $container->getAlias(ViewerContextInterface::class) !== AllowAllViewerContext::class) {
            return;
        }

        $container->log($this, sprintf(
            '%s is still aliased to %s: every user sees and manages the whole Shared Drive. '
            . 'Implement ViewerContextInterface in your application to restrict visibility.',
            ViewerContextInterface::class,
            AllowAllViewerContext::class
        ));
    }
}
