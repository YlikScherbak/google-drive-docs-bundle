<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * Google refused to copy the item.
 *
 * In practice this means a folder: the Drive API has no folder copy, exactly like
 * Google's own UI, where "Make a copy" is unavailable for folders. Copying a tree
 * means recreating the folders and copying each file into them.
 *
 * Map it to HTTP 400 — the request asked for something Drive cannot do.
 */
class NotCopyableException extends \RuntimeException
{
}
