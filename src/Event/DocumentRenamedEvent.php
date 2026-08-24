<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/** A document or folder was renamed. */
final class DocumentRenamedEvent extends DriveEvent
{
    public function __construct(public readonly DriveDocument $document)
    {
        parent::__construct($document->id);
    }
}
