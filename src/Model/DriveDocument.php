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
        ];
    }
}
