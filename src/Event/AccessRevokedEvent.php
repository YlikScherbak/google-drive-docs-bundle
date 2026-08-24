<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/** Access to a document or folder was revoked. */
final class AccessRevokedEvent extends DriveEvent
{
    public function __construct(
        string $fileId,
        public readonly string $permissionId,
    ) {
        parent::__construct($fileId);
    }
}
