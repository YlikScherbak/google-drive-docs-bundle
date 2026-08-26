<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * One version of an item, as Drive kept it.
 *
 * Two things about revisions are worth knowing before building on them.
 *
 * The list Google returns **may be incomplete**. Its own documentation says older revisions
 * are omitted for files with a long history — frequently edited Sheets and Docs especially —
 * and that the history shown in the Workspace editor can be more complete than the API's.
 * Pin what matters with `keepRevision()`; do not treat this as an audit trail.
 *
 * And there is no way to make an old revision current again: Drive API v3 lists, reads, pins
 * and deletes revisions, but only the Google editor restores one in place. Recovering old
 * content therefore means writing it somewhere new — see `recoverRevision()`.
 */
final class DriveRevision
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $modifiedTime,
        /** Display name of whoever saved this version, falling back to their e-mail. */
        public readonly ?string $modifiedBy,
        /** Bytes, or null for a Google document — those store nothing of their own. */
        public readonly ?int $size,
        /**
         * Whether Google is keeping this version regardless of how the history is pruned.
         * Only a limited number of revisions may be pinned per file.
         */
        public readonly bool $keptForever = false,
        public readonly ?string $mimeType = null,
        /** Name the file had when this version was saved, for uploaded files. */
        public readonly ?string $originalFilename = null,
        /**
         * Where this version's content can be fetched, keyed by MIME type. Present for
         * Google's own formats, which have no stored bytes to download directly.
         *
         * @var array<string, string>
         */
        public readonly array $exportLinks = [],
    ) {
    }

    /** Whether this version's content can be fetched at all, and in which formats. */
    public function isExportable(): bool
    {
        return $this->exportLinks !== [] || $this->size !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'modifiedTime'     => $this->modifiedTime,
            'modifiedBy'       => $this->modifiedBy,
            'size'             => $this->size,
            'keptForever'      => $this->keptForever,
            'mimeType'         => $this->mimeType,
            'originalFilename' => $this->originalFilename,
            'exportLinks'      => array_keys($this->exportLinks),
        ];
    }
}
