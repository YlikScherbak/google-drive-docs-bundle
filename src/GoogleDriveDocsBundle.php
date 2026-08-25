<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle;

use Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler\ValidateCachePoolPass;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler\WarnDefaultViewerContextPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class GoogleDriveDocsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ValidateCachePoolPass());
        $container->addCompilerPass(new WarnDefaultViewerContextPass());
    }
}
