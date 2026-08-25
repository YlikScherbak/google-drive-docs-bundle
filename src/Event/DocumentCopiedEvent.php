<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;

/**
 * A document was duplicated, either on its own or out of a template.
 *
 * The inherited `fileId` is the **copy**; `sourceId` is what it was copied from,
 * which is the template id when the copy came from createFromTemplate().
 */
final class DocumentCopiedEvent extends DriveEvent
{
    public function __construct(
        public readonly DriveDocument $document,
        public readonly string $sourceId,
        public readonly ?string $parentId = null,
    ) {
        parent::__construct($document->id);
    }
}
