<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/** A tab was renamed. Its numeric sheet id does not change, only the title does. */
final class SheetTabRenamedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $from,
        public readonly string $to,
        public readonly int $sheetId,
    ) {
        parent::__construct($fileId);
    }
}
