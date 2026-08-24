<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * Bundle is missing mandatory configuration (shared drive id, OAuth credentials).
 * Map it to HTTP 503.
 */
class NotConfiguredException extends \RuntimeException
{
}
