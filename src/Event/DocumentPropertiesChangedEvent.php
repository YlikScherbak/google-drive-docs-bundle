<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Event;

/**
 * The application's own metadata on an item was changed.
 *
 * `properties` is what was sent, not the resulting set: a null value means that key was
 * removed. Drive keeps these private to the OAuth client that wrote them, so nothing else
 * looking at the drive sees them.
 */
final class DocumentPropertiesChangedEvent extends DriveEvent
{
    /**
     * @param array<string, string|null> $properties
     */
    public function __construct(
        string $fileId,
        public readonly array $properties,
    ) {
        parent::__construct($fileId);
    }
}
