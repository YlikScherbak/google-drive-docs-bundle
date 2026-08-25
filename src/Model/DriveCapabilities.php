<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * What Google will actually allow on an item, one flag per bundle operation.
 *
 * Use it to decide which buttons to render, instead of showing every action and
 * letting Google reject half of them. A folder on a Shared Drive where the service
 * user is a "Content manager", for instance, reports `canTrash: true` but
 * `canDelete: false` — trashing works, erasing for good does not.
 *
 * **These flags describe the service user, not the person browsing.** The bundle talks
 * to Google as one account, so this answers "will the call succeed", never "is this
 * viewer allowed to". Visibility per viewer stays with `ViewerContextInterface` and
 * `canAccess()`; combine the two in your UI.
 *
 * Every flag defaults to false: a capability Google did not report is treated as
 * not granted rather than assumed.
 */
final class DriveCapabilities
{
    public function __construct(
        /** rename(), and editing the document itself */
        public readonly bool $canEdit = false,
        public readonly bool $canRename = false,
        /** deleteForever() — needs the Manager role on the Shared Drive */
        public readonly bool $canDelete = false,
        /** trash() */
        public readonly bool $canTrash = false,
        /** restore() */
        public readonly bool $canUntrash = false,
        /** grant() / grantToGroup() / revoke() */
        public readonly bool $canShare = false,
        /** copy() / createFromTemplate() */
        public readonly bool $canCopy = false,
        /** export() */
        public readonly bool $canDownload = false,
        /** createDocument() / createFolder() / import() inside this folder */
        public readonly bool $canAddChildren = false,
        /** move() */
        public readonly bool $canMove = false,
    ) {
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'canEdit'        => $this->canEdit,
            'canRename'      => $this->canRename,
            'canDelete'      => $this->canDelete,
            'canTrash'       => $this->canTrash,
            'canUntrash'     => $this->canUntrash,
            'canShare'       => $this->canShare,
            'canCopy'        => $this->canCopy,
            'canDownload'    => $this->canDownload,
            'canAddChildren' => $this->canAddChildren,
            'canMove'        => $this->canMove,
        ];
    }
}
