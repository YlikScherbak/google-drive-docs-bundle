<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * A formatting pass was applied to a spreadsheet.
 *
 * `operations` is how many changes travelled in the one request — a styling pass is
 * usually several, and they either all land or none do.
 */
final class SheetFormattedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly int $operations,
    ) {
        parent::__construct($fileId);
    }
}
