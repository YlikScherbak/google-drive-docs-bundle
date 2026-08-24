<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;

/** Someone was given access to a document or folder. */
final class AccessGrantedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly DrivePermission $permission,
    ) {
        parent::__construct($fileId);
    }
}
