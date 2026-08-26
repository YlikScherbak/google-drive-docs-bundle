<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * One thing that happened to an item since the token you asked from.
 *
 * `removed` covers both directions of disappearing: the item was deleted, or it left the
 * scope being watched — the drive, or what the service user can see. Either way there is no
 * document to describe, so `document` is null.
 */
final class DriveChange
{
    public function __construct(
        public readonly string $fileId,
        public readonly bool $removed,
        public readonly ?string $time = null,
        /** The item as it stands now, or null when it is gone. */
        public readonly ?DriveDocument $document = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fileId'   => $this->fileId,
            'removed'  => $this->removed,
            'time'     => $this->time,
            'document' => $this->document?->toArray(),
        ];
    }
}
