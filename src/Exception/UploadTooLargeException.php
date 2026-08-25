<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * The file is bigger than a single Drive upload accepts.
 *
 * Google's multipart upload — the one-request form the bundle uses — is capped at
 * 5 MB. Bigger files need Drive's resumable protocol, which the bundle does not
 * implement yet; until then such a file has to go in through Google Drive itself.
 *
 * Map it to HTTP 413.
 */
class UploadTooLargeException extends \RuntimeException
{
}
