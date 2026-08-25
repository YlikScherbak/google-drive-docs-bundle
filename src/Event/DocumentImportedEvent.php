<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/**
 * A file was uploaded into the drive, converted to a Google document or stored as it was.
 *
 * `originalFilename` is the name of the uploaded file including its extension, which the
 * document itself no longer carries once Google converted it — keep it for the audit trail.
 */
final class DocumentImportedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $document,
        public readonly string $originalFilename,
        public readonly ?string $parentId = null,
    ) {
        parent::__construct($document->id);
    }
}
