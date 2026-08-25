<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Service;

use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Event\AccessGrantedEvent;
use Borsche\GoogleDriveDocsBundle\Event\AccessRevokedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentCopiedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentCreatedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentImportedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentMovedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRenamedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRestoredEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentTrashedEvent;
use Borsche\GoogleDriveDocsBundle\Event\FolderCreatedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\InsufficientDriveRoleException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Exception\NotCopyableException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Exception\UploadTooLargeException;
use Borsche\GoogleDriveDocsBundle\Model\DriveCapabilities;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use Borsche\GoogleDriveDocsBundle\Model\DrivePage;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileCapabilities;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Exception as GoogleServiceException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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

    /**
     * Roles from weakest to strongest, for resolving what a viewer effectively holds.
     *
     * Wider than ALLOWED_ROLES on purpose: the bundle cannot hand out owner, organizer or
     * fileOrganizer — those come from the Shared Drive's own membership — but it will meet
     * them when reading and must report them rather than pretend they are unknown.
     */
    private const ROLE_STRENGTH = [
        DrivePermission::ROLE_READER,
        DrivePermission::ROLE_COMMENTER,
        DrivePermission::ROLE_WRITER,
        'fileOrganizer',
        'organizer',
        'owner',
    ];

    private const ALLOWED_TYPES = [
        DrivePermission::TYPE_USER,
        DrivePermission::TYPE_GROUP,
    ];

    /**
     * Only the capabilities the bundle exposes are requested. Asking for the whole
     * capabilities object would add some thirty booleans per file to every listing.
     */
    private const CAPABILITY_FIELDS = 'capabilities(canEdit,canRename,canDelete,canTrash,'
        . 'canUntrash,canShare,canCopy,canDownload,canAddChildren,canMoveItemWithinDrive)';

    private const FILE_FIELDS = 'id,name,mimeType,webViewLink,modifiedTime,trashed,'
        . 'createdTime,size,iconLink,thumbnailLink,lastModifyingUser(displayName,emailAddress),'
        . self::CAPABILITY_FIELDS;

    /** Items per page when the caller does not say otherwise. */
    public const DEFAULT_PAGE_SIZE = 100;

    /** The largest page Google's files.list accepts. */
    public const MAX_PAGE_SIZE = 1000;

    /**
     * Pages a single walk may fetch before it is treated as a runaway. A million items or
     * a hundred thousand sharing entries is far beyond any real folder; a token that never
     * runs out is an API fault, and looping on it would exhaust memory instead of failing.
     */
    private const MAX_PAGES = 1000;

    /** Google's ceiling for a one-request multipart upload. */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const FALLBACK_MIME = 'application/octet-stream';

    /**
     * File extension to the MIME type of the uploaded bytes.
     *
     * Derived from the extension rather than sniffed: the extension is what the user
     * chose, while mime_content_type() happily reports "application/zip" for .xlsx.
     *
     * @var array<string, string>
     */
    private const UPLOAD_MIMES = [
        'xlsx' => DriveExport::XLSX,
        'ods'  => DriveExport::ODS,
        'csv'  => DriveExport::CSV,
        'tsv'  => DriveExport::TSV,
        'docx' => DriveExport::DOCX,
        'odt'  => DriveExport::ODT,
        'rtf'  => DriveExport::RTF,
        'txt'  => DriveExport::TXT,
        'html' => DriveExport::HTML,
        'htm'  => DriveExport::HTML,
        'pptx' => DriveExport::PPTX,
        'odp'  => DriveExport::ODP,
        'pdf'  => DriveExport::PDF,
        'zip'  => DriveExport::ZIP,
    ];

    /**
     * Uploaded type to the Google type it converts into. Anything missing here — a PDF,
     * an archive, an unknown extension — has no Google editor equivalent and is stored as is.
     *
     * @var array<string, string>
     */
    private const GOOGLE_EQUIVALENTS = [
        DriveExport::XLSX => 'application/vnd.google-apps.spreadsheet',
        DriveExport::ODS  => 'application/vnd.google-apps.spreadsheet',
        DriveExport::CSV  => 'application/vnd.google-apps.spreadsheet',
        DriveExport::TSV  => 'application/vnd.google-apps.spreadsheet',
        DriveExport::DOCX => 'application/vnd.google-apps.document',
        DriveExport::ODT  => 'application/vnd.google-apps.document',
        DriveExport::RTF  => 'application/vnd.google-apps.document',
        DriveExport::TXT  => 'application/vnd.google-apps.document',
        DriveExport::HTML => 'application/vnd.google-apps.document',
        DriveExport::PPTX => 'application/vnd.google-apps.presentation',
        DriveExport::ODP  => 'application/vnd.google-apps.presentation',
    ];

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
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?CacheItemPoolInterface $permissionCache = null,
        private readonly int $permissionCacheTtl = 300,
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
        [$q, $filter] = $this->folderCriteria($parentId);

        return $this->queryAll($q, $filter);
    }

    /**
     * One page of a folder's contents. Prefer this over listFolder() on a large drive:
     * it costs a single files.list call instead of one per hundred items.
     *
     * Pass the previous page's nextPageToken to continue; drive the loop off
     * DrivePage::hasMore(), because visibility filtering can leave a page short or empty
     * while later pages still hold documents.
     */
    public function listFolderPage(
        ?string $parentId = null,
        ?string $pageToken = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): DrivePage {
        [$q, $filter] = $this->folderCriteria($parentId);

        return $this->queryPage($q, $filter, $pageToken, $pageSize);
    }

    /**
     * Search the whole Shared Drive by name, filtered for the current viewer.
     *
     * @return DriveDocument[]
     */
    public function search(string $name): array
    {
        $criteria = $this->searchCriteria($name);

        return $criteria === null ? [] : $this->queryAll($criteria[0], $criteria[1]);
    }

    /**
     * One page of search results. A blank query yields an empty page without asking Google.
     */
    public function searchPage(
        string $name,
        ?string $pageToken = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): DrivePage {
        $criteria = $this->searchCriteria($name);

        return $criteria === null
            ? new DrivePage([])
            : $this->queryPage($criteria[0], $criteria[1], $pageToken, $pageSize);
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
     * Upload a local file into the drive, by default converting it into the matching
     * Google document so it opens in the embedded editor.
     *
     * Pass the path of a file on disk — `$uploadedFile->getPathname()` for an HTTP upload,
     * or any path when importing from a command. A spreadsheet (`.xlsx`, `.ods`, `.csv`,
     * `.tsv`) becomes a Google Sheet, a text document (`.docx`, `.odt`, `.rtf`, `.txt`,
     * `.html`) a Google Doc, a deck (`.pptx`, `.odp`) a Google Slides. Anything without a
     * Google equivalent — a PDF, an archive — is stored unchanged whatever `$convert` says.
     *
     * @param string|null $title      Defaults to the file name, without the extension when converting
     * @param bool        $convert    Set false to keep the uploaded file exactly as it is
     * @param string|null $mimeType   Overrides the type guessed from the extension
     *
     * @throws UploadTooLargeException   above MAX_UPLOAD_BYTES
     * @throws \InvalidArgumentException when the path is not a readable file
     */
    public function import(
        string $path,
        ?string $title = null,
        ?string $parentId = null,
        bool $convert = true,
        ?string $mimeType = null
    ): DriveDocument {
        $this->assertConfigured();

        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Cannot read the file to import: "%s".', $path));
        }

        // Checked twice on purpose: the stat keeps an oversized file out of memory, and the
        // byte count catches a stat that failed or a file that grew in between.
        $size = filesize($path);

        if ($size !== false) {
            $this->assertUploadSize($size);
        }

        if ($parentId !== null) {
            $this->assertAccess($parentId);
        }

        // Bounded read: when the stat above failed there is nothing else stopping a huge
        // file from being pulled into memory before the size check below can reject it.
        $contents = file_get_contents($path, false, null, 0, self::MAX_UPLOAD_BYTES + 1);

        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Could not read the file to import: "%s".', $path));
        }

        $this->assertUploadSize(strlen($contents));

        $filename   = basename($path);
        $extension  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $uploadMime = $mimeType ?? self::UPLOAD_MIMES[$extension] ?? self::FALLBACK_MIME;
        $targetMime = $convert ? (self::GOOGLE_EQUIVALENTS[$uploadMime] ?? $uploadMime) : $uploadMime;

        // Once Google converts the file the extension is meaningless, so it goes away.
        $converted = $targetMime !== $uploadMime;
        $name      = $title ?? ($converted ? pathinfo($filename, PATHINFO_FILENAME) : $filename);

        $created = $this->drive->files->create(new DriveFile([
            'name'     => $name,
            'mimeType' => $targetMime,
            'parents'  => [$parentId ?: $this->sharedDriveId],
        ]), [
            'data'              => $contents,
            'mimeType'          => $uploadMime,
            'uploadType'        => 'multipart',
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]);

        $document = $this->mapFile($created);
        $this->dispatch(new DocumentImportedEvent($document, $filename, $parentId));

        return $document;
    }

    /**
     * Render a document into a downloadable format and stream it back.
     *
     * Google converts its own documents on the fly — pass one of the DriveExport
     * constants as the target format. Google caps a single export at **10 MB**; larger
     * documents are refused with a Google error, so offer the lighter formats (CSV over
     * XLSX, for instance) for heavy spreadsheets.
     *
     * A file that is not a Google document has nothing to convert: its stored bytes are
     * returned as they are and the requested format is ignored. Check the returned
     * `mimeType` rather than assuming it matches what you asked for.
     */
    public function export(string $fileId, string $mimeType): DriveExport
    {
        $document = $this->get($fileId);
        $name     = $document->name ?? $fileId;

        if ($this->isGoogleDocument($document->mimeType)) {
            return new DriveExport(
                $this->exportFilename($name, $mimeType),
                $mimeType,
                $this->bodyOf($this->drive->files->export($fileId, $mimeType, ['alt' => 'media']))
            );
        }

        return new DriveExport(
            $name,
            $document->mimeType ?? 'application/octet-stream',
            $this->bodyOf($this->drive->files->get($fileId, [
                'alt'               => 'media',
                'supportsAllDrives' => true,
            ]))
        );
    }

    /**
     * Create an empty document. Defaults to the first configured document MIME type.
     */
    public function createDocument(string $title, ?string $parentId = null, ?string $mimeType = null): DriveDocument
    {
        $document = $this->createFile($title, $mimeType ?? $this->defaultDocumentMime(), $parentId);
        $this->dispatch(new DocumentCreatedEvent($document, $parentId));

        return $document;
    }

    public function createFolder(string $title, ?string $parentId = null): DriveDocument
    {
        $folder = $this->createFile($title, self::FOLDER_MIME, $parentId);
        $this->dispatch(new FolderCreatedEvent($folder, $parentId));

        return $folder;
    }

    /**
     * Duplicate a document. Without a target folder the copy lands next to the original,
     * which is what Google itself does; pass a folder id to place it elsewhere.
     *
     * Only files can be copied. The Drive API has no folder copy, so a folder id raises
     * NotCopyableException instead of silently doing nothing.
     *
     * @throws NotCopyableException when the item is a folder or another non-copyable type
     */
    public function copy(string $fileId, ?string $title = null, ?string $parentId = null): DriveDocument
    {
        $this->assertAccess($fileId);

        if ($parentId !== null) {
            $this->assertAccess($parentId);
        }

        $payload = new DriveFile();

        if ($title !== null) {
            $payload->setName($title);
        }

        if ($parentId !== null) {
            $payload->setParents([$parentId]);
        }

        try {
            $copy = $this->drive->files->copy($fileId, $payload, [
                'supportsAllDrives' => true,
                'fields'            => self::FILE_FIELDS,
            ]);
        } catch (GoogleServiceException $e) {
            if ($this->hasReason($e, ['fileNotCopyable', 'cannotCopyFile'])) {
                throw new NotCopyableException(
                    'Google cannot copy this item. Folders in particular have no copy operation: '
                    . 'recreate the folder with createFolder() and copy its files into it one by one.',
                    $e->getCode(),
                    $e
                );
            }

            throw $e;
        }

        $document = $this->mapFile($copy);
        $this->dispatch(new DocumentCopiedEvent($document, $fileId, $parentId));

        return $document;
    }

    /**
     * Start a new document from a template by copying it under a new name.
     *
     * A template is an ordinary document — keep them in a folder of their own and the
     * copy carries over every formula, sheet and piece of formatting it contains.
     */
    public function createFromTemplate(string $templateId, string $title, ?string $parentId = null): DriveDocument
    {
        return $this->copy($templateId, $title, $parentId);
    }

    public function rename(string $fileId, string $title): DriveDocument
    {
        $this->assertAccess($fileId);

        $document = $this->mapFile($this->drive->files->update($fileId, new DriveFile(['name' => $title]), [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));

        $this->dispatch(new DocumentRenamedEvent($document));

        return $document;
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

        $previousParents = $current->getParents() ?? [];

        if ($previousParents === []) {
            throw new UnexpectedDriveStateException(sprintf(
                'Google did not report the current parent of "%s", so it cannot be moved safely.',
                $fileId
            ));
        }

        $document = $this->mapFile($this->drive->files->update($fileId, new DriveFile(), [
            'addParents'        => $parentId ?: $this->sharedDriveId,
            'removeParents'     => implode(',', $previousParents),
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));

        $this->dispatch(new DocumentMovedEvent($document, $previousParents[0] ?? null, $parentId));

        return $document;
    }

    /**
     * Move a file or folder to the trash. It disappears from the regular listings
     * but stays restorable until Google purges the trash (30 days on a Shared Drive).
     */
    public function trash(string $fileId): DriveDocument
    {
        $document = $this->setTrashed($fileId, true);
        $this->dispatch(new DocumentTrashedEvent($document));

        return $document;
    }

    /**
     * Take a file or folder back out of the trash.
     *
     * Note that an item returns to its original parent — if that folder is itself
     * in the trash, restore it as well to make the item reachable again.
     */
    public function restore(string $fileId): DriveDocument
    {
        $document = $this->setTrashed($fileId, false);
        $this->dispatch(new DocumentRestoredEvent($document));

        return $document;
    }

    /**
     * Trashed items of the whole Shared Drive, filtered for the current viewer.
     *
     * Google only flags the item that was trashed, not its contents, so a trashed
     * folder shows up here on its own — exactly as it does in Google's own UI.
     *
     * @return DriveDocument[]
     */
    public function listTrash(): array
    {
        [$q, $filter] = $this->trashCriteria();

        return $this->queryAll($q, $filter);
    }

    /** One page of the trash. */
    public function listTrashPage(
        ?string $pageToken = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): DrivePage {
        [$q, $filter] = $this->trashCriteria();

        return $this->queryPage($q, $filter, $pageToken, $pageSize);
    }

    /**
     * Erase a file or folder for good, skipping the trash. This cannot be undone.
     *
     * @throws InsufficientDriveRoleException when the service user is not a Manager of the drive
     */
    public function deleteForever(string $fileId): void
    {
        $this->assertAccess($fileId);

        try {
            $this->drive->files->delete($fileId, ['supportsAllDrives' => true]);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 403 && !$this->isRateLimited($e)) {
                throw new InsufficientDriveRoleException(
                    'Deleting for good requires the service user to be a Manager of the Shared Drive; '
                    . '"Content manager" may only move items to the trash. Either raise the role in '
                    . 'Google Drive or call trash() instead.',
                    $e->getCode(),
                    $e
                );
            }

            throw $e;
        }

        $this->forgetGrants($fileId);

        $this->dispatch(new DocumentDeletedEvent($fileId));
    }

    /**
     * @deprecated since 0.3.0, use deleteForever() to keep erasing items for good,
     *             or trash() to move them to the trash instead.
     */
    public function delete(string $fileId): void
    {
        trigger_deprecation(
            'borsche/google-drive-docs-bundle',
            '0.3.0',
            'Calling "%s()" is deprecated, use "deleteForever()" for the same behaviour or "trash()" to move the item to the trash instead.',
            __METHOD__
        );

        $this->deleteForever($fileId);
    }

    /**
     * Sharing entries of a file or folder, including the ones inherited from parents.
     *
     * @return DrivePermission[]
     */
    public function listPermissions(string $fileId): array
    {
        $this->assertAccess($fileId);

        $permissions = [];
        $pageToken   = null;
        $pages       = 0;

        do {
            $this->assertPageBudget(++$pages, 'permissions.list');

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
    public function grant(
        string $fileId,
        string $email,
        string $role = DrivePermission::ROLE_WRITER,
        string $type = DrivePermission::TYPE_USER
    ): DrivePermission {
        // Local validation first: a bad role must not cost a walk up the parent chain.
        $this->assertRole($role);
        $this->assertType($type);
        $this->assertAccess($fileId);

        return $this->share($fileId, $email, $role, $type);
    }

    /**
     * The sharing call itself, on already validated input. Callers decide whether the
     * viewer's access had to be proven first.
     */
    private function share(string $fileId, string $email, string $role, string $type): DrivePermission
    {
        $created = $this->drive->permissions->create($fileId, new GooglePermission([
            'type'         => $type,
            'role'         => $role,
            'emailAddress' => $email,
        ]), [
            'supportsAllDrives'     => true,
            'sendNotificationEmail' => $this->notifyOnShare,
            'fields'                => 'id,emailAddress,role,type,displayName',
        ]);

        $this->forgetGrants($fileId);

        $permission = $this->mapPermission($created, $role, $email, $type);
        $this->dispatch(new AccessGrantedEvent($fileId, $permission));

        return $permission;
    }

    /**
     * Share on behalf of the application, skipping the viewer's access check.
     *
     * `grant()` refuses to touch an item the current viewer cannot reach, which is what stops
     * anyone holding a file id from sharing a stranger's document with themselves. That check
     * gets in the way of the one case where the **application**, not the viewer, is acting:
     * a document the service user has just created is shared with nobody, so the grant that
     * gives the creator access is itself a grant on an item the creator cannot yet see.
     *
     *     public function onCreated(DocumentCreatedEvent $event): void
     *     {
     *         if (!$this->viewerContext->seesEverything()) {
     *             $this->drive->grantAsService($event->fileId, $this->creatorEmail(), 'writer');
     *         }
     *     }
     *
     * Reach for it only where the decision to share is the application's own and already
     * authorised by something else — never with a file id that came from a request. It is a
     * separate method rather than a flag on `grant()` so that every place bypassing the check
     * can be found with a single grep.
     */
    public function grantAsService(
        string $fileId,
        string $email,
        string $role = DrivePermission::ROLE_WRITER,
        string $type = DrivePermission::TYPE_USER
    ): DrivePermission {
        $this->assertRole($role);
        $this->assertType($type);

        return $this->share($fileId, $email, $role, $type);
    }

    /**
     * Share with a Google group — the usual way to give a whole team access at once.
     */
    public function grantToGroup(
        string $fileId,
        string $groupEmail,
        string $role = DrivePermission::ROLE_WRITER
    ): DrivePermission {
        return $this->grant($fileId, $groupEmail, $role, DrivePermission::TYPE_GROUP);
    }

    public function revoke(string $fileId, string $permissionId): void
    {
        $this->assertAccess($fileId);

        try {
            $this->drive->permissions->delete($fileId, $permissionId, ['supportsAllDrives' => true]);
        } catch (GoogleServiceException $e) {
            if ($this->hasReason($e, ['cannotDeletePermission', 'cannotModifyInheritedPermission'])) {
                throw new InheritedPermissionException(
                    'This permission is inherited from a parent folder. Revoke it on that folder instead.'
                );
            }

            throw $e;
        }

        $this->forgetGrants($fileId);

        $this->dispatch(new AccessRevokedEvent($fileId, $permissionId));
    }

    /**
     * Whether the viewer may see an item: shared directly with them, or with any ancestor folder.
     */
    public function canAccess(string $fileId, ?string $email = null): bool
    {
        if ($this->viewerContext->seesEverything()) {
            return true;
        }

        // Without the drive id the walk could never reach the root and would burn 25 calls for nothing.
        $this->assertConfigured();

        $identities = $email !== null && $email !== ''
            ? [$this->normalizeEmail($email)]
            : $this->viewerIdentities();

        if ($identities === []) {
            return false;
        }

        $cursor = $fileId;
        $guard  = 0;

        while ($cursor !== null && $cursor !== $this->sharedDriveId && $guard++ < 25) {
            try {
                $file = $this->drive->files->get($cursor, [
                    'supportsAllDrives' => true,
                    'fields'            => 'id,parents,permissions(emailAddress,type,role)',
                ]);
            } catch (GoogleServiceException $e) {
                // An item the service user cannot even see is not shared with anyone we know of.
                // Anything else — an outage, an expired credential — must surface, or every
                // document would silently disappear for every viewer.
                if (in_array($e->getCode(), [403, 404], true)) {
                    return false;
                }

                throw $e;
            }

            if ($this->isGrantedTo($file, $identities)) {
                return true;
            }

            $cursor = ($file->getParents() ?? [])[0] ?? null;
        }

        return false;
    }

    /**
     * The role the current viewer effectively holds on an item, or null when they hold none.
     *
     * Reports, it does not enforce: canAccess() still passes anyone with any grant, and the
     * bundle still performs the operation as the service user. Use this to decide what to
     * offer — grey out an edit button for a reader — and keep the decision in your own
     * authorisation layer.
     *
     * Where several grants apply — named directly and through a group, on the item and on a
     * folder above it — the strongest wins, which is how Google resolves it too. Roles the
     * bundle cannot grant (owner, organizer, fileOrganizer) are reported as they are.
     *
     * Null for a viewer whose seesEverything() is true: nothing is looked up for them, and a
     * role would not mean anything, since they bypass filtering and act as the service user.
     */
    public function roleOf(string $fileId): ?string
    {
        if ($this->viewerContext->seesEverything()) {
            return null;
        }

        $this->assertConfigured();

        $identities = $this->viewerIdentities();

        if ($identities === []) {
            return null;
        }

        $best   = null;
        $cursor = $fileId;
        $guard  = 0;

        while ($cursor !== null && $cursor !== $this->sharedDriveId && $guard++ < 25) {
            try {
                $file = $this->drive->files->get($cursor, [
                    'supportsAllDrives' => true,
                    'fields'            => 'id,parents,permissions(emailAddress,type,role)',
                ]);
            } catch (GoogleServiceException $e) {
                if (in_array($e->getCode(), [403, 404], true)) {
                    return $best;
                }

                throw $e;
            }

            foreach ($this->grantsFor($file, $identities) as $role) {
                $best = $this->strongerRole($best, $role);
            }

            $cursor = ($file->getParents() ?? [])[0] ?? null;
        }

        return $best;
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
     * The query and filtering flag for a folder listing.
     *
     * Entering a folder is an all-or-nothing decision: once access to the folder itself
     * is proven, everything inside is visible (Google inherits sharing), so per-item
     * filtering can be switched off for the rest of the listing.
     *
     * @return array{0: string, 1: bool}
     */
    private function folderCriteria(?string $parentId): array
    {
        $this->assertConfigured();

        if ($parentId !== null) {
            $this->assertFileId($parentId);
        }

        $needFilter = !$this->viewerContext->seesEverything();

        if ($needFilter && $parentId !== null) {
            $this->assertAccess($parentId);
            $needFilter = false;
        }

        $parent = $parentId ?: $this->sharedDriveId;

        return [
            sprintf("%s and '%s' in parents and trashed=false", $this->typeFilter(), $parent),
            $needFilter,
        ];
    }

    /**
     * The query and filtering flag for a search, or null when there is nothing to search for.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function searchCriteria(string $name): ?array
    {
        $this->assertConfigured();

        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);

        return [
            sprintf("%s and name contains '%s' and trashed=false", $this->typeFilter(), $escaped),
            !$this->viewerContext->seesEverything(),
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function trashCriteria(): array
    {
        $this->assertConfigured();

        return [
            sprintf('%s and trashed=true', $this->typeFilter()),
            !$this->viewerContext->seesEverything(),
        ];
    }

    /**
     * Every matching item, walking the pages for the caller. Uses the largest page Google
     * allows, so a big folder costs ten times fewer round trips than a hundred-item page would.
     *
     * @return DriveDocument[]
     */
    private function queryAll(string $q, bool $filter): array
    {
        $documents = [];
        $pageToken = null;
        $pages     = 0;

        do {
            $this->assertPageBudget(++$pages, 'files.list');

            $page = $this->queryPage($q, $filter, $pageToken, self::MAX_PAGE_SIZE);

            foreach ($page->items as $document) {
                $documents[] = $document;
            }

            $pageToken = $page->nextPageToken;
        } while ($pageToken !== null);

        return $documents;
    }

    /**
     * A single files.list call, mapped and filtered for the current viewer.
     */
    private function queryPage(string $q, bool $filter, ?string $pageToken, int $pageSize): DrivePage
    {
        $identities = $filter ? $this->viewerIdentities() : [];

        if ($filter && $identities === []) {
            return new DrivePage([]);
        }

        $params = [
            'q'                         => $q,
            'corpora'                   => 'drive',
            'driveId'                   => $this->sharedDriveId,
            'includeItemsFromAllDrives' => true,
            'supportsAllDrives'         => true,
            'fields'                    => 'nextPageToken, files(' . self::FILE_FIELDS . ',permissions(emailAddress,type,role))',
            'orderBy'                   => 'folder,modifiedTime desc',
            'pageSize'                  => max(1, min($pageSize, self::MAX_PAGE_SIZE)),
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->drive->files->listFiles($params);

        $documents = [];

        foreach ($response->getFiles() as $file) {
            if ($filter && !$this->isGrantedTo($file, $identities)) {
                continue;
            }

            $documents[] = $this->mapFile($file);
        }

        // Google signals the end either by omitting the token or by sending an empty one.
        $next = $response->getNextPageToken();

        return new DrivePage($documents, $next !== null && $next !== '' ? $next : null);
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

    private function setTrashed(string $fileId, bool $trashed): DriveDocument
    {
        $this->assertAccess($fileId);

        return $this->mapFile($this->drive->files->update($fileId, new DriveFile(['trashed' => $trashed]), [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));
    }

    /**
     * Direct grants of an item. Shared Drives usually omit "permissions" from
     * files.list/get, so the dedicated permissions.list call is the reliable source.
     *
     * @return array<string, string> normalised e-mail => strongest role held on the item
     */
    private function directGrants(string $fileId): array
    {
        if (isset($this->grantCache[$fileId])) {
            return $this->grantCache[$fileId];
        }

        // A zero TTL keeps the lookups out of the shared pool: an entry saved without a
        // lifetime would otherwise live there until the pool is cleared.
        $item = $this->permissionCacheTtl > 0
            ? $this->permissionCache?->getItem($this->cacheKey($fileId))
            : null;

        if ($item !== null && $item->isHit()) {
            /** @var array<string, string> $cached */
            $cached = $item->get();

            return $this->grantCache[$fileId] = $cached;
        }

        $grants    = [];
        $pageToken = null;
        $pages     = 0;

        try {
            do {
                $this->assertPageBudget(++$pages, 'permissions.list');

                $params = [
                    'supportsAllDrives' => true,
                    'fields'            => 'nextPageToken, permissions(emailAddress,type,role)',
                    'pageSize'          => 100,
                ];

                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }

                $response = $this->drive->permissions->listPermissions($fileId, $params);

                foreach ($response->getPermissions() as $permission) {
                    if (!in_array($permission->getType(), self::ALLOWED_TYPES, true)
                        || !$permission->getEmailAddress()) {
                        continue;
                    }

                    $email = $this->normalizeEmail($permission->getEmailAddress());

                    $grants[$email] = $this->strongerRole($grants[$email] ?? null, $permission->getRole())
                        ?? DrivePermission::ROLE_READER;
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken !== null);
        } catch (\Exception) {
            // Hide the item for this request only: a transient API error must not hide
            // documents for the whole TTL. Errors (TypeError and friends) are bugs and propagate.
            return $this->grantCache[$fileId] = [];
        }

        if ($item !== null) {
            $item->set($grants);
            $item->expiresAfter($this->permissionCacheTtl);
            $this->permissionCache?->save($item);
        }

        return $this->grantCache[$fileId] = $grants;
    }

    /**
     * @param string[] $identities normalised e-mail plus the viewer group addresses
     */
    private function isGrantedTo(DriveFile $file, array $identities): bool
    {
        return $this->grantsFor($file, $identities) !== [];
    }

    /**
     * The roles the given identities hold on an item, from the file's own permissions when
     * Google sent them and from the dedicated lookup otherwise.
     *
     * @param string[] $identities normalised e-mail plus the viewer group addresses
     * @return list<string> one role per matching grant, unranked
     */
    private function grantsFor(DriveFile $file, array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $roles = [];

        foreach ($file->getPermissions() ?? [] as $permission) {
            if (in_array($permission->getType(), self::ALLOWED_TYPES, true)
                && in_array($this->normalizeEmail((string) $permission->getEmailAddress()), $identities, true)) {
                $roles[] = $permission->getRole() ?? DrivePermission::ROLE_READER;
            }
        }

        if ($roles !== []) {
            return $roles;
        }

        foreach ($this->directGrants($file->getId()) as $email => $role) {
            if (in_array($email, $identities, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * The stronger of two roles, treating an unrecognised one as the weaker candidate so a
     * role Google may add later never silently outranks a known one.
     */
    private function strongerRole(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        $a = array_search($current, self::ROLE_STRENGTH, true);
        $b = array_search($candidate, self::ROLE_STRENGTH, true);

        return ($b === false ? -1 : $b) > ($a === false ? -1 : $a) ? $candidate : $current;
    }

    /**
     * Everything the current viewer can be addressed by: own e-mail and group addresses.
     *
     * @return string[]
     */
    private function viewerIdentities(): array
    {
        $identities = [];
        $email      = $this->viewerContext->getViewerEmail();

        if ($email !== null && $email !== '') {
            $identities[] = $this->normalizeEmail($email);
        }

        foreach ($this->viewerContext->getViewerGroups() as $group) {
            if ($group !== '') {
                $identities[] = $this->normalizeEmail($group);
            }
        }

        return array_values(array_unique($identities));
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
        $user     = $file->getLastModifyingUser();
        $size     = $file->getSize();

        return new DriveDocument(
            $file->getId(),
            $file->getName(),
            $file->getMimeType(),
            $file->getWebViewLink(),
            $file->getModifiedTime(),
            $isFolder ? DriveDocument::TYPE_FOLDER : DriveDocument::TYPE_DOCUMENT,
            (bool) $file->getTrashed(),
            $file->getCreatedTime(),
            // Drive sends int64 as a string, and omits it entirely for Google's own formats.
            $size !== null ? (int) $size : null,
            $file->getIconLink(),
            $file->getThumbnailLink(),
            $user !== null ? ($user->getDisplayName() ?: $user->getEmailAddress()) : null,
            $this->mapCapabilities($file->getCapabilities()),
        );
    }

    private function mapCapabilities(?DriveFileCapabilities $capabilities): ?DriveCapabilities
    {
        if ($capabilities === null) {
            return null;
        }

        return new DriveCapabilities(
            (bool) $capabilities->getCanEdit(),
            (bool) $capabilities->getCanRename(),
            (bool) $capabilities->getCanDelete(),
            (bool) $capabilities->getCanTrash(),
            (bool) $capabilities->getCanUntrash(),
            (bool) $capabilities->getCanShare(),
            (bool) $capabilities->getCanCopy(),
            (bool) $capabilities->getCanDownload(),
            (bool) $capabilities->getCanAddChildren(),
            (bool) $capabilities->getCanMoveItemWithinDrive(),
        );
    }

    private function mapPermission(
        GooglePermission $permission,
        ?string $fallbackRole = null,
        ?string $fallbackEmail = null,
        ?string $fallbackType = null
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
            $permission->getType() ?? $fallbackType ?? ($fallbackEmail !== null ? DrivePermission::TYPE_USER : null),
            $permission->getDisplayName(),
            $inherited,
            $inheritedFrom,
        );
    }

    private function isGoogleDocument(?string $mimeType): bool
    {
        return $mimeType !== null && str_starts_with($mimeType, 'application/vnd.google-apps.');
    }

    /** Appends the format's extension unless the document name already carries it. */
    private function exportFilename(string $name, string $mimeType): string
    {
        $extension = DriveExport::extensionFor($mimeType);

        if ($extension === null || str_ends_with(strtolower($name), '.' . $extension)) {
            return $name;
        }

        return $name . '.' . $extension;
    }

    /**
     * The response body of a media call. With "alt=media" the Google client skips
     * decoding and hands back the PSR-7 response itself, which keeps the download
     * streaming instead of loading it into memory.
     */
    private function bodyOf(mixed $response): StreamInterface
    {
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException(sprintf(
                'Expected a streamed HTTP response from Google, got %s.',
                get_debug_type($response)
            ));
        }

        return $response->getBody();
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

    /**
     * Drops the cached sharing of an item after it changed.
     *
     * Only the item itself needs clearing: access checks walk the parent chain and
     * read each ancestor's own entry, so a grant on a folder is picked up by its children.
     */
    private function forgetGrants(string $fileId): void
    {
        unset($this->grantCache[$fileId]);

        $this->permissionCache?->deleteItem($this->cacheKey($fileId));
    }

    private function cacheKey(string $fileId): string
    {
        // PSR-6 keys allow a limited character set; Google file ids do not fit it.
        // The version travels in the key: 0.4.0 and earlier cached a plain list of e-mails
        // where this now expects a role map, and reading one as the other is nonsense.
        return 'google_drive_docs.grants.v2.' . sha1($fileId);
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported grantee type "%s". Allowed: %s.',
                $type,
                implode(', ', self::ALLOWED_TYPES)
            ));
        }
    }

    /**
     * @throws UploadTooLargeException
     */
    private function assertUploadSize(int $bytes): void
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new UploadTooLargeException(sprintf(
                'This file is %d bytes, and a single Drive upload takes at most %d. Google needs its '
                . 'resumable protocol beyond that, which this bundle does not implement yet — add the '
                . 'file through Google Drive itself, or split it.',
                $bytes,
                self::MAX_UPLOAD_BYTES
            ));
        }
    }

    /**
     * Drive reports exhausted quota behind a 403; once the retries are spent it arrives here.
     */
    private function isRateLimited(GoogleServiceException $e): bool
    {
        return $this->hasReason($e, ['rateLimitExceeded', 'userRateLimitExceeded']);
    }

    /**
     * Whether Google attached one of the given machine-readable reasons to the error.
     * The wording of the message is not a contract; the reason is.
     *
     * getErrors() is null, not empty, whenever the response body carried no "error.errors"
     * — a proxy 502, an empty 429 — so the coalesce is load-bearing: iterating null raises a
     * warning that a strict error handler turns into a throw, masking the real failure.
     *
     * @param string[] $reasons
     */
    private function hasReason(GoogleServiceException $e, array $reasons): bool
    {
        foreach ($e->getErrors() ?? [] as $error) {
            if (in_array($error['reason'] ?? null, $reasons, true)) {
                return true;
            }
        }

        return false;
    }

    private function assertPageBudget(int $pages, string $call): void
    {
        if ($pages > self::MAX_PAGES) {
            throw new UnexpectedDriveStateException(sprintf(
                'Google kept returning a nextPageToken for %s beyond %d pages; giving up on what looks like a runaway listing.',
                $call,
                self::MAX_PAGES
            ));
        }
    }

    /**
     * Ids are interpolated into Drive queries, so only Google's own alphabet may pass.
     */
    private function assertFileId(string $fileId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $fileId) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid Google Drive item id.', $fileId));
        }
    }

    private function assertConfigured(): void
    {
        if ($this->sharedDriveId === '') {
            throw new NotConfiguredException('shared_drive_id is not configured.');
        }
    }
}
