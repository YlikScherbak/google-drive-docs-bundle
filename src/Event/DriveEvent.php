<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * Base class for every event dispatched by the bundle.
 *
 * Listen to these to add auditing, notifications or cache invalidation without
 * touching the bundle itself.
 */
abstract class DriveEvent
{
    public function __construct(public readonly string $fileId)
    {
    }
}
