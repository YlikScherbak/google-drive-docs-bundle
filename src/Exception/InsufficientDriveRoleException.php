<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Exception;

/**
 * The service user's role on the Shared Drive is too low for the requested operation.
 *
 * Typically raised by deleteForever(): Google only lets a **Manager** erase an item
 * for good, while the setup described in the README adds the service user as
 * "Content manager" (role "fileOrganizer"), which may trash but not purge.
 *
 * This is a configuration problem on the Google side, not an end-user access
 * decision — unlike AccessDeniedException it should be logged for an administrator
 * rather than rendered as a plain 403.
 */
class InsufficientDriveRoleException extends \RuntimeException
{
}
