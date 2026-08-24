<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * Thrown when revoking a permission that is inherited from a parent folder.
 * Google only allows removing it where it was granted. Map it to HTTP 400.
 */
class InheritedPermissionException extends \RuntimeException
{
}
