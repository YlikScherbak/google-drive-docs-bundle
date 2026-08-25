<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler\WarnDefaultViewerContextPass;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\GoogleDriveDocsExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Running with the "everyone sees everything" default is legitimate, but it should never
 * happen by accident: the compiler log has to say so.
 */
final class WarnDefaultViewerContextPassTest extends TestCase
{
    public function testTheDefaultContextIsNotedInTheCompilerLog(): void
    {
        $container = $this->container();

        (new WarnDefaultViewerContextPass())->process($container);

        $log = implode("\n", $container->getCompiler()->getLog());
        self::assertStringContainsString('AllowAllViewerContext', $log);
        self::assertStringContainsString('ViewerContextInterface', $log);
    }

    public function testAnApplicationContextPassesQuietly(): void
    {
        $container = $this->container();
        $container->setDefinition('app.viewer_context', new Definition(\stdClass::class));
        $container->setAlias(ViewerContextInterface::class, 'app.viewer_context');

        (new WarnDefaultViewerContextPass())->process($container);

        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testAMissingAliasIsLeftToTheContainerToReport(): void
    {
        $container = $this->container();
        $container->removeAlias(ViewerContextInterface::class);

        (new WarnDefaultViewerContextPass())->process($container);

        self::assertSame([], $container->getCompiler()->getLog());
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new GoogleDriveDocsExtension())->load([['shared_drive_id' => 'drive']], $container);

        return $container;
    }
}
