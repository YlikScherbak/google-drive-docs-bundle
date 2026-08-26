<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * A version was removed from an item's history.
 *
 * There is nothing behind this: the content that version held is gone, and Drive has no
 * trash for a revision.
 */
final class RevisionDeletedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $revisionId,
    ) {
        parent::__construct($fileId);
    }
}
