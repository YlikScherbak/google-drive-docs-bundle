<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * A tab and everything on it were removed.
 *
 * There is no trash for a tab: the only way back is the spreadsheet's own version history,
 * so this is the event to keep if anyone ever has to ask what happened.
 */
final class SheetTabDeletedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $title,
        public readonly int $sheetId,
    ) {
        parent::__construct($fileId);
    }
}
