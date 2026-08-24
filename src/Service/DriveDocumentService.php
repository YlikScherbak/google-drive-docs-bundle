<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Service;

use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Exception as GoogleServiceException;

/**
 * Document workspace on top of a Google Shared Drive.
 *
 * Files stay in Google (formulas, formatting and charts keep working); the
 * application only browses, creates and shares them. Sharing lives in Google as
 * well — the bundle never keeps its own copy of the access rules.
 */
class DriveDocumentService
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const ALLOWED_ROLES = [
        DrivePermission::ROLE_READER,
        DrivePermission::ROLE_COMMENTER,
        DrivePermission::ROLE_WRITER,
    ];

    private const FILE_FIELDS = 'id,name,mimeType,webViewLink,modifiedTime';

    /**
     * Per-request cache of direct grants: fileId => list of e-mails.
     *
     * @var array<string, string[]>
     */
    private array $grantCache = [];

    /**
     * @param string[] $documentMimeTypes
     */
    public function __construct(
        private readonly Drive $drive,
        private readonly ViewerContextInterface $viewerContext,
        private readonly string $sharedDriveId,
        private readonly array $documentMimeTypes,
        private readonly bool $notifyOnShare = false,
    ) {
    }

    /**
     * Folder contents (sub-folders first, then documents), filtered for the current viewer.
     * Pass null to list the root of the Shared Drive.
     *
     * @return DriveDocument[]
     */
    public function listFolder(?string $parentId = null): array
    {
        $this->assertConfigured();

        $needFilter = !$this->viewerContext->seesEverything();

        // Entering a folder is an all-or-nothing decision: once access to the folder
        // itself is proven, everything inside is visible (Google inherits sharing).
        if ($needFilter && $parentId !== null) {
            $this->assertAccess($parentId);
            $needFilter = false;
        }

        $parent = $parentId ?: $this->sharedDriveId;

        return $this->query(
            sprintf("%s and '%s' in parents and trashed=false", $this->typeFilter(), $parent),
            $needFilter
        );
    }

    /**
     * Search the whole Shared Drive by name, filtered for the current viewer.
     *
     * @return DriveDocument[]
     */
    public function search(string $name): array
    {
        $this->assertConfigured();

        $name = trim($name);

        if ($name === '') {
            return [];
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);

        return $this->query(
            sprintf("%s and name contains '%s' and trashed=false", $this->typeFilter(), $escaped),
            !$this->viewerContext->seesEverything()
        );
    }

    public function get(string $fileId): DriveDocument
    {
        $this->assertAccess($fileId);

        return $this->mapFile($this->drive->files->get($fileId, [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));
    }

    /**
     * Create an empty document. Defaults to the first configured document MIME type.
     */
    public function createDocument(string $title, ?string $parentId = null, ?string $mimeType = null): DriveDocument
    {
        return $this->createFile($title, $mimeType ?? $this->defaultDocumentMime(), $parentId);
    }

    public function createFolder(string $title, ?string $parentId = null): DriveDocument
    {
        return $this->createFile($title, self::FOLDER_MIME, $parentId);
    }

    public function rename(string $fileId, string $title): DriveDocument
    {
        $this->assertAccess($fileId);

        return $this->mapFile($this->drive->files->update($fileId, new DriveFile(['name' => $title]), [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));
    }

    /**
     * Move a file or folder. Null target means the root of the Shared Drive.
     */
    public function move(string $fileId, ?string $parentId): DriveDocument
    {
        $this->assertConfigured();
        $this->assertAccess($fileId);

        if ($parentId !== null) {
            $this->assertAccess($parentId);
        }

        // On a Shared Drive an item has exactly one parent, so the old one must go.
        $current = $this->drive->files->get($fileId, [
            'supportsAllDrives' => true,
            'fields'            => 'parents',
        ]);

        return $this->mapFile($this->drive->files->update($fileId, new DriveFile(), [
            'addParents'        => $parentId ?: $this->sharedDriveId,
            'removeParents'     => implode(',', $current->getParents() ?? []),
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));
    }

    public function delete(string $fileId): void
    {
        $this->assertAccess($fileId);

        $this->drive->files->delete($fileId, ['supportsAllDrives' => true]);
    }

    /**
     * Sharing entries of a file or folder, including the ones inherited from parents.
     *
     * @return DrivePermission[]
     */
    public function listPermissions(string $fileId): array
    {
        $permissions = [];
        $pageToken   = null;

        do {
            $params = [
                'supportsAllDrives' => true,
                'fields'            => 'nextPageToken, permissions(id,emailAddress,role,type,displayName,permissionDetails)',
                'pageSize'          => 100,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->drive->permissions->listPermissions($fileId, $params);

            foreach ($response->getPermissions() as $permission) {
                $permissions[] = $this->mapPermission($permission);
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken !== null);

        return $permissions;
    }

    /**
     * Share a file or folder with a Google account. Sharing a folder cascades to everything inside.
     */
    public function grant(string $fileId, string $email, string $role = DrivePermission::ROLE_WRITER): DrivePermission
    {
        $this->assertRole($role);

        $created = $this->drive->permissions->create($fileId, new GooglePermission([
            'type'         => 'user',
            'role'         => $role,
            'emailAddress' => $email,
        ]), [
            'supportsAllDrives'     => true,
            'sendNotificationEmail' => $this->notifyOnShare,
            'fields'                => 'id,emailAddress,role,type,displayName',
        ]);

        unset($this->grantCache[$fileId]);

        return $this->mapPermission($created, $role, $email);
    }

    public function revoke(string $fileId, string $permissionId): void
    {
        try {
            $this->drive->permissions->delete($fileId, $permissionId, ['supportsAllDrives' => true]);
        } catch (GoogleServiceException $e) {
            if (str_contains($e->getMessage(), 'inherited') || str_contains($e->getMessage(), 'cannotDeletePermission')) {
                throw new InheritedPermissionException(
                    'This permission is inherited from a parent folder. Revoke it on that folder instead.'
                );
            }

            throw $e;
        }

        unset($this->grantCache[$fileId]);
    }

    /**
     * Whether the viewer may see an item: shared directly with them, or with any ancestor folder.
     */
    public function canAccess(string $fileId, ?string $email = null): bool
    {
        if ($this->viewerContext->seesEverything()) {
            return true;
        }

        $email = $email ?? $this->viewerContext->getViewerEmail();

        if ($email === null || $email === '') {
            return false;
        }

        $cursor = $fileId;
        $guard  = 0;

        while ($cursor !== null && $cursor !== $this->sharedDriveId && $guard++ < 25) {
            try {
                $file = $this->drive->files->get($cursor, [
                    'supportsAllDrives' => true,
                    'fields'            => 'id,parents,permissions(emailAddress,type)',
                ]);
            } catch (\Throwable) {
                return false;
            }

            if ($this->isGrantedTo($file, $email)) {
                return true;
            }

            $cursor = ($file->getParents() ?? [])[0] ?? null;
        }

        return false;
    }

    /**
     * @throws AccessDeniedException
     */
    public function assertAccess(string $fileId): void
    {
        if (!$this->canAccess($fileId)) {
            throw new AccessDeniedException('You have no access to this item.');
        }
    }

    /**
     * @return DriveDocument[]
     */
    private function query(string $q, bool $filter): array
    {
        $viewerEmail = $filter ? $this->viewerContext->getViewerEmail() : null;

        if ($filter && ($viewerEmail === null || $viewerEmail === '')) {
            return [];
        }

        $documents = [];
        $pageToken = null;

        do {
            $params = [
                'q'                         => $q,
                'corpora'                   => 'drive',
                'driveId'                   => $this->sharedDriveId,
                'includeItemsFromAllDrives' => true,
                'supportsAllDrives'         => true,
                'fields'                    => 'nextPageToken, files(' . self::FILE_FIELDS . ',permissions(emailAddress,type))',
                'orderBy'                   => 'folder,modifiedTime desc',
                'pageSize'                  => 100,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->drive->files->listFiles($params);

            foreach ($response->getFiles() as $file) {
                if ($filter && !$this->isGrantedTo($file, $viewerEmail)) {
                    continue;
                }

                $documents[] = $this->mapFile($file);
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken !== null);

        return $documents;
    }

    private function createFile(string $title, string $mimeType, ?string $parentId): DriveDocument
    {
        $this->assertConfigured();

        if ($parentId !== null) {
            $this->assertAccess($parentId);
        }

        $created = $this->drive->files->create(new DriveFile([
            'name'     => $title,
            'mimeType' => $mimeType,
            'parents'  => [$parentId ?: $this->sharedDriveId],
        ]), [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]);

        return $this->mapFile($created);
    }

    /**
     * Direct grants of an item. Shared Drives usually omit "permissions" from
     * files.list/get, so the dedicated permissions.list call is the reliable source.
     *
     * @return string[] normalised e-mails
     */
    private function directGrantEmails(string $fileId): array
    {
        if (isset($this->grantCache[$fileId])) {
            return $this->grantCache[$fileId];
        }

        $emails    = [];
        $pageToken = null;

        try {
            do {
                $params = [
                    'supportsAllDrives' => true,
                    'fields'            => 'nextPageToken, permissions(emailAddress,type)',
                    'pageSize'          => 100,
                ];

                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }

                $response = $this->drive->permissions->listPermissions($fileId, $params);

                foreach ($response->getPermissions() as $permission) {
                    if ($permission->getType() === 'user' && $permission->getEmailAddress()) {
                        $emails[] = $this->normalizeEmail($permission->getEmailAddress());
                    }
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken !== null);
        } catch (\Throwable) {
            return $this->grantCache[$fileId] = [];
        }

        return $this->grantCache[$fileId] = $emails;
    }

    private function isGrantedTo(DriveFile $file, ?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $needle = $this->normalizeEmail($email);

        foreach ($file->getPermissions() ?? [] as $permission) {
            if ($permission->getType() === 'user'
                && $this->normalizeEmail((string) $permission->getEmailAddress()) === $needle) {
                return true;
            }
        }

        return in_array($needle, $this->directGrantEmails($file->getId()), true);
    }

    /** Lower-case and collapse Gmail "+tag" aliases (Google treats them as the same account). */
    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if (preg_match('/^([^+@]+)\+[^@]*(@.+)$/', $email, $m)) {
            $email = $m[1] . $m[2];
        }

        return $email;
    }

    private function mapFile(DriveFile $file): DriveDocument
    {
        $isFolder = $file->getMimeType() === self::FOLDER_MIME;

        return new DriveDocument(
            $file->getId(),
            $file->getName(),
            $file->getMimeType(),
            $file->getWebViewLink(),
            $file->getModifiedTime(),
            $isFolder ? DriveDocument::TYPE_FOLDER : DriveDocument::TYPE_DOCUMENT,
        );
    }

    private function mapPermission(
        GooglePermission $permission,
        ?string $fallbackRole = null,
        ?string $fallbackEmail = null
    ): DrivePermission {
        $inherited     = false;
        $inheritedFrom = null;

        foreach ($permission->getPermissionDetails() ?? [] as $details) {
            if ($details->getInherited()) {
                $inherited     = true;
                $inheritedFrom = $details->getInheritedFrom();
                break;
            }
        }

        return new DrivePermission(
            $permission->getId(),
            $permission->getEmailAddress() ?? $fallbackEmail,
            $permission->getRole() ?? $fallbackRole,
            $permission->getType() ?? ($fallbackEmail !== null ? 'user' : null),
            $permission->getDisplayName(),
            $inherited,
            $inheritedFrom,
        );
    }

    private function typeFilter(): string
    {
        $mimes = array_merge([self::FOLDER_MIME], $this->documentMimeTypes);
        $parts = array_map(static fn (string $mime): string => sprintf("mimeType='%s'", $mime), $mimes);

        return '(' . implode(' or ', $parts) . ')';
    }

    private function defaultDocumentMime(): string
    {
        return $this->documentMimeTypes[0] ?? 'application/vnd.google-apps.spreadsheet';
    }

    private function assertRole(string $role): void
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported role "%s". Allowed: %s.',
                $role,
                implode(', ', self::ALLOWED_ROLES)
            ));
        }
    }

    private function assertConfigured(): void
    {
        if ($this->sharedDriveId === '') {
            throw new NotConfiguredException('shared_drive_id is not configured.');
        }
    }
}
