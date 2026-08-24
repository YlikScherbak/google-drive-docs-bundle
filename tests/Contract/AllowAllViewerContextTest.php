<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Contract;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use PHPUnit\Framework\TestCase;

final class AllowAllViewerContextTest extends TestCase
{
    public function testItDisablesFiltering(): void
    {
        $context = new AllowAllViewerContext();

        self::assertTrue($context->seesEverything());
        self::assertNull($context->getViewerEmail());
    }
}
