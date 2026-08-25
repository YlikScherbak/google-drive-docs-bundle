<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * A folder or a document living on the Shared Drive.
 */
final class DriveDocument
{
    public const TYPE_FOLDER   = 'folder';
    public const TYPE_DOCUMENT = 'document';

    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly ?string $mimeType,
        /** Open this URL in an iframe to edit the document with the native Google editor. */
        public readonly ?string $webViewLink,
        public readonly ?string $modifiedTime,
        /** self::TYPE_FOLDER or self::TYPE_DOCUMENT */
        public readonly string $type,
        /** In the trash: hidden from normal listings, restorable until Google purges it. */
        public readonly bool $trashed = false,
        public readonly ?string $createdTime = null,
        /** Bytes on the drive, or null for a Google document — those store nothing of their own. */
        public readonly ?int $size = null,
        /** Stable Google-hosted type icon, safe to embed directly. */
        public readonly ?string $iconLink = null,
        /** Preview image. Short-lived and authenticated — see the README before embedding it. */
        public readonly ?string $thumbnailLink = null,
        /** Display name of whoever last changed the item, falling back to their e-mail. */
        public readonly ?string $lastModifiedBy = null,
        /** What Google will allow here, or null when the listing did not ask for it. */
        public readonly ?DriveCapabilities $capabilities = null,
    ) {
    }

    public function isFolder(): bool
    {
        return $this->type === self::TYPE_FOLDER;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'mimeType'     => $this->mimeType,
            'webViewLink'  => $this->webViewLink,
            'modifiedTime' => $this->modifiedTime,
            'type'         => $this->type,
            'trashed'      => $this->trashed,
            'createdTime'  => $this->createdTime,
            'size'         => $this->size,
            'iconLink'     => $this->iconLink,
            'thumbnailLink' => $this->thumbnailLink,
            'lastModifiedBy' => $this->lastModifiedBy,
            'capabilities' => $this->capabilities?->toArray(),
        ];
    }
}
