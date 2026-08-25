<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/**
 * A document or folder was moved to the trash and can still be restored.
 * Google empties the trash of a Shared Drive automatically after 30 days.
 */
final class DocumentTrashedEvent extends DriveEvent
{
    public function __construct(public readonly DriveDocument $document)
    {
        parent::__construct($document->id);
    }
}
