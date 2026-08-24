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

    /**
     * Google group addresses the viewer belongs to (e.g. "portugal@example.com").
     *
     * Sharing a folder with a group is the usual way to give a whole team access;
     * the bundle cannot read group membership from Google, so the application
     * supplies it here. Return an empty array when groups are not used.
     *
     * @return string[]
     */
    public function getViewerGroups(): array;
}
