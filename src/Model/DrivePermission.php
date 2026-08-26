<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * A sharing entry of a file or folder.
 */
final class DrivePermission
{
    public const TYPE_USER  = 'user';
    public const TYPE_GROUP = 'group';

    public const ROLE_READER    = 'reader';
    public const ROLE_COMMENTER = 'commenter';
    public const ROLE_WRITER    = 'writer';

    public function __construct(
        public readonly string $id,
        public readonly ?string $emailAddress,
        public readonly ?string $role,
        public readonly ?string $type,
        public readonly ?string $displayName,
        /** Inherited permissions can only be revoked on the folder that granted them. */
        public readonly bool $inherited = false,
        public readonly ?string $inheritedFrom = null,
        /**
         * When Google will drop this grant by itself, RFC 3339, or null for a lasting one.
         * Google only allows an expiry on user and group grants, no more than a year ahead.
         */
        public readonly ?string $expiresAt = null,
    ) {
    }

    /** Whether this grant goes away on its own. */
    public function expires(): bool
    {
        return $this->expiresAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'emailAddress'  => $this->emailAddress,
            'role'          => $this->role,
            'type'          => $this->type,
            'displayName'   => $this->displayName,
            'inherited'     => $this->inherited,
            'inheritedFrom' => $this->inheritedFrom,
            'expiresAt'     => $this->expiresAt,
        ];
    }
}
