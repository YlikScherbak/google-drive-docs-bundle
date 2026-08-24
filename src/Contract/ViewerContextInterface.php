<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Contract;

/**
 * Tells the bundle who is browsing the drive right now.
 *
 * Implement it in your application (e.g. read the Google e-mail from your User
 * entity and treat admins as unrestricted) and alias it:
 *
 *     Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface: '@App\Drive\MyViewerContext'
 */
interface ViewerContextInterface
{
    /**
     * Google account e-mail of the current user, or null when unknown.
     * Visibility is resolved against the sharing permissions of this address.
     */
    public function getViewerEmail(): ?string;

    /**
     * When true the viewer bypasses visibility filtering and sees the whole drive
     * (typically administrators or users allowed to manage sharing).
     */
    public function seesEverything(): bool;
}
