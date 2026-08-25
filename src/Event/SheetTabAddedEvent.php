<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * A tab was added to a spreadsheet.
 *
 * `sheetId` is the numeric id Google assigned it, which is what its formatting calls take —
 * keep it if the next thing you do is style what you just created.
 */
final class SheetTabAddedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $title,
        public readonly int $sheetId,
    ) {
        parent::__construct($fileId);
    }
}
