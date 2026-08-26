<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * A version was pinned against pruning, or released again.
 *
 * Worth recording: Google prunes revision history on its own, and only a limited number of
 * versions may be pinned per file, so which ones are kept is a decision someone made.
 */
final class RevisionKeptEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $revisionId,
        public readonly bool $kept,
    ) {
        parent::__construct($fileId);
    }
}
