<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * The values of a range were emptied. Formatting and formulas pointing at it stay.
 */
final class SheetRangeClearedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $range,
    ) {
        parent::__construct($fileId);
    }
}
