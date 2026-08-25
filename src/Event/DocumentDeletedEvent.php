<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/** A document or folder was erased for good, bypassing the trash (see DocumentTrashedEvent for the trash). */
final class DocumentDeletedEvent extends DriveEvent
{
}
