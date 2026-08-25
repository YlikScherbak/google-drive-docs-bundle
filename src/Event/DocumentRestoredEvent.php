<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/**
 * A document or folder was taken out of the trash.
 */
final class DocumentRestoredEvent extends DriveEvent
{
    public function __construct(public readonly DriveDocument $document)
    {
        parent::__construct($document->id);
    }
}
