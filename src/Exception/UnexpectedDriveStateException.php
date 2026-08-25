<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * Google answered in a way that cannot be true, so the bundle refuses to guess.
 *
 * Raised when an item on a Shared Drive reports no parent at all, and when a listing
 * keeps handing out a `nextPageToken` far past any plausible number of pages. Neither is
 * the caller's fault and neither is a permission problem — continuing would mean acting
 * on a state that does not make sense.
 *
 * Map it to HTTP 500 and log it: it points at an API fault or an outage, not at input.
 */
class UnexpectedDriveStateException extends \RuntimeException
{
}
