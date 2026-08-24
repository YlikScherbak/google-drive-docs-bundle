<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/** A document was created. */
final class DocumentCreatedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $document,
        public readonly ?string $parentId = null,
    ) {
        parent::__construct($document->id);
    }
}
