<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/**
 * An item was locked against editing, or released again.
 *
 * The reason is what Google shows the person who tries to edit it, so it is worth keeping in
 * the audit trail alongside who decided the document was final.
 */
final class DocumentLockChangedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $document,
        public readonly bool $locked,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($document->id);
    }
}
