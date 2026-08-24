<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/** A document or folder was moved to another folder. */
final class DocumentMovedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $document,
        public readonly ?string $fromParentId,
        public readonly ?string $toParentId,
    ) {
        parent::__construct($document->id);
    }
}
