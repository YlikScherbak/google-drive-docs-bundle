<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * Rows were added after the last used row of a spreadsheet.
 *
 * `range` is where the block landed as Google reports it ("'Q3'!A10:B12"), or the range
 * that was asked for when Google stays silent. `rows` is what the caller handed over, not
 * what Google echoed back: an append always writes every row it is given.
 */
final class SheetRowsAppendedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $range,
        public readonly int $rows,
    ) {
        parent::__construct($fileId);
    }
}
