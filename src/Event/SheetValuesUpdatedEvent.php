<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * Cells of a spreadsheet were overwritten.
 *
 * `range` is the A1 notation the write targeted; `rows` is how many rows Google reports
 * it changed, which is 0 when the new values were identical to the old ones.
 */
final class SheetValuesUpdatedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $range,
        public readonly int $rows,
    ) {
        parent::__construct($fileId);
    }
}
