<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * The current viewer has no access to the requested file or folder.
 * Map it to HTTP 403 in your controller.
 */
class AccessDeniedException extends \RuntimeException
{
}
