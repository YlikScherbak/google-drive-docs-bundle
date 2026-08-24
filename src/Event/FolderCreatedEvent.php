<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/** A folder was created. */
final class FolderCreatedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $folder,
        public readonly ?string $parentId = null,
    ) {
        parent::__construct($folder->id);
    }
}
