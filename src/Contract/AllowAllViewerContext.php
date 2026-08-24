<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Contract;

/**
 * Default context: no filtering at all.
 *
 * Keeps the bundle usable out of the box (single-tenant back office, CLI usage).
 * Replace it as soon as documents must be visible only to the people they are shared with.
 */
final class AllowAllViewerContext implements ViewerContextInterface
{
    public function getViewerEmail(): ?string
    {
        return null;
    }

    public function seesEverything(): bool
    {
        return true;
    }
}
