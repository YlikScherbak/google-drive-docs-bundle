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
    ) {
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
        ];
    }
}
