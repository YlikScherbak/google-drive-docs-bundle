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
use Borsche\GoogleDriveDocsBundle\Event\DocumentLockChangedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentMovedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentPropertiesChangedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRenamedEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentRestoredEvent;
use Borsche\GoogleDriveDocsBundle\Event\DocumentTrashedEvent;
use Borsche\GoogleDriveDocsBundle\Event\RevisionDeletedEvent;
use Borsche\GoogleDriveDocsBundle\Event\RevisionKeptEvent;
use Borsche\GoogleDriveDocsBundle\Event\FolderCreatedEvent;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Exception\InheritedPermissionException;
use Borsche\GoogleDriveDocsBundle\Exception\InsufficientDriveRoleException;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Exception\NotCopyableException;
use Borsche\GoogleDriveDocsBundle\Exception\UnexpectedDriveStateException;
use Borsche\GoogleDriveDocsBundle\Exception\UploadTooLargeException;
use Borsche\GoogleDriveDocsBundle\Model\DriveCapabilities;
use Borsche\GoogleDriveDocsBundle\Model\DriveChange;
use Borsche\GoogleDriveDocsBundle\Model\DriveChanges;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use Borsche\GoogleDriveDocsBundle\Model\DrivePage;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Borsche\GoogleDriveDocsBundle\Model\DriveRevision;
use Google\Service\Drive;
use Google\Http\MediaFileUpload;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Change as GoogleChange;
use Google\Service\Drive\ContentRestriction;
use Google\Service\Drive\DriveFileCapabilities;
use Google\Service\Drive\Permission as GooglePermission;
use Google\Service\Drive\Revision as GoogleRevision;
use Google\Service\Exception as GoogleServiceException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Document workspace on top of a Google Shared Drive.
 *
 * Files stay in Google (formulas, formatting and charts keep working); the
 * application only browses, creates and shares them. Sharing lives in Google as
 * well — the bundle never keeps its own copy of the access rules.
 *
 * Google's own failures are not wrapped: `Google\Service\Exception` propagates out of every
 * method here, carrying Drive's status and message, and is meant to be handled once where the
 * application decides what an outage looks like. Only the cases the bundle can say something
 * more useful about — an inherited grant, a folder asked to be copied — get an exception of
 * their own; those are the ones carrying a `@throws`.
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
        . 'contentRestrictions(readOnly,reason),'
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

    /**
     * Google's ceiling for a one-request multipart upload. Past this the resumable protocol
     * takes over, so this is where the two paths part rather than a limit on what can go up.
     */
    public const MULTIPART_LIMIT = 5 * 1024 * 1024;

    /** Resumable chunks must be a multiple of this. */
    public const CHUNK_GRANULARITY = 256 * 1024;

    /**
     * The largest chunk worth allowing. Every chunk is read into memory whole, so a bigger
     * one buys fewer round trips at the price of the PHP memory limit; past this it is a
     * configuration mistake rather than a tuning choice.
     */
    public const MAX_CHUNK_BYTES = 64 * 1024 * 1024;

    private const FALLBACK_MIME = 'application/octet-stream';

    private const REVISION_FIELDS = 'id,modifiedTime,keepForever,size,mimeType,'
        . 'originalFilename,exportLinks,lastModifyingUser(displayName,emailAddress)';

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
     * Per-request cache of direct grants: fileId => (normalised e-mail => strongest role).
     *
     * @var array<string, array<string, string>>
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
        /** A cap of your own in bytes; 0 leaves Drive's own 5 TB as the only one. */
        private readonly int $maxUploadBytes = 0,
        /** Bytes per resumable chunk. Bigger is fewer round trips and more memory. */
        private readonly int $chunkBytes = 8 * 1024 * 1024,
    ) {
        if ($this->chunkBytes <= 0 || $this->chunkBytes % self::CHUNK_GRANULARITY !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'A resumable chunk must be a positive multiple of %d bytes, %d given.',
                self::CHUNK_GRANULARITY,
                $this->chunkBytes
            ));
        }

        if ($this->chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'A resumable chunk of %d bytes is read into memory whole; %d is the most this '
                . 'bundle will take.',
                $this->chunkBytes,
                self::MAX_CHUNK_BYTES
            ));
        }
    }

    /**
     * The same service pointed at another Shared Drive.
     *
     * One configured drive is the common case, so it stays the constructor's business; a
     * second workspace — a department, a client — is this. Everything else is carried over
     * unchanged: the viewer context, the MIME types, the cache and its lifetime, the upload
     * limits. The instance you called it on is untouched.
     *
     * Built by hand rather than cloned, because readonly properties cannot be reassigned;
     * DriveDocumentServiceMultiDriveTest walks the constructor to notice an argument that
     * stops being carried over.
     */
    public function forDrive(string $driveId): self
    {
        if ($driveId === $this->sharedDriveId) {
            return $this;
        }

        if ($driveId === '') {
            throw new NotConfiguredException('A drive id is required; "" is not one.');
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $driveId) !== 1) {
            throw new NotConfiguredException(sprintf('"%s" is not a Google Drive id.', $driveId));
        }

        return new self(
            $this->drive,
            $this->viewerContext,
            $driveId,
            $this->documentMimeTypes,
            $this->notifyOnShare,
            $this->dispatcher,
            $this->permissionCache,
            $this->permissionCacheTtl,
            $this->maxUploadBytes,
            $this->chunkBytes,
        );
    }

    /**
     * Folder contents (sub-folders first, then documents), filtered for the current viewer.
     * Pass null to list the root of the Shared Drive.
     *
     * Trashing a folder flags the folder alone — Google leaves the contents' own state
     * untouched — so this still lists what is inside a folder that is in the trash. Settled
     * deliberately as of 1.0: checking would put another Drive call on the hottest path here
     * to cover a case only a stale link or bookmark reaches, and where the viewer sees
     * nothing they could not already see. Guard it where the data is already at hand —
     * a page that renders breadcrumbs has fetched the folder, and get() reports `trashed`.
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
     * @throws UploadTooLargeException   above the upload.max_bytes you configured
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

        // A stat is a fast path, not an authority: a network mount or a stream wrapper can
        // answer 0 for a file full of bytes. It buys an early refusal when it is right, and
        // both upload paths check the size again against what they actually read.
        $size = filesize($path);

        if ($size !== false) {
            $this->assertUploadSize($size);
        }

        if ($parentId !== null) {
            $this->assertAccess($parentId);
        }

        $filename   = basename($path);
        $extension  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $uploadMime = $mimeType ?? self::UPLOAD_MIMES[$extension] ?? self::FALLBACK_MIME;
        $targetMime = $convert ? (self::GOOGLE_EQUIVALENTS[$uploadMime] ?? $uploadMime) : $uploadMime;

        // Once Google converts the file the extension is meaningless, so it goes away.
        $converted = $targetMime !== $uploadMime;
        $name      = $title ?? ($converted ? pathinfo($filename, PATHINFO_FILENAME) : $filename);

        $metadata = new DriveFile([
            'name'     => $name,
            'mimeType' => $targetMime,
            'parents'  => [$parentId ?: $this->sharedDriveId],
        ]);

        // Under Google's multipart ceiling the whole thing goes in one request; past it the
        // resumable protocol sends the bytes in chunks, so nothing has to be held in memory.
        $created = $size !== false && $size > self::MULTIPART_LIMIT
            ? $this->uploadResumable($path, $metadata, $uploadMime, $size)
            : $this->uploadAtOnce($path, $metadata, $uploadMime);

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
     * Where to start watching from. Ask once, store it, then poll with changesSince().
     */
    public function startPageToken(): string
    {
        $this->assertConfigured();

        $token = $this->drive->changes->getStartPageToken([
            'driveId'           => $this->sharedDriveId,
            'supportsAllDrives' => true,
        ])->getStartPageToken();

        if ($token === null || $token === '') {
            throw new UnexpectedDriveStateException('Google did not hand back a start token to watch from.');
        }

        return $token;
    }

    /**
     * Everything that happened on the drive since the given token.
     *
     * This is how the bundle finds out about work done **directly in Google** — a share added
     * in the Drive UI, a document renamed by hand. Without it the sharing cache only notices
     * such things when it expires, so every change seen here drops that item's cached
     * sharing straight away.
     *
     * Store the returned nextToken and hand it back next time; the bundle has nowhere to keep
     * it. Every page is walked before returning, so the token is only ever the end of a
     * complete batch.
     *
     * This is the drive's feed, not the viewer's: it is not filtered by ViewerContext and
     * describes every item the service user can see — names, links, who last edited what.
     * Call it from the job that keeps your application in step with Drive, never from a
     * request on a viewer's behalf.
     */
    public function changesSince(string $pageToken): DriveChanges
    {
        $this->assertConfigured();

        $changes = [];
        $pages   = 0;
        $next    = null;

        do {
            $this->assertPageBudget(++$pages, 'changes.list');

            $response = $this->drive->changes->listChanges($pageToken, [
                'driveId'                   => $this->sharedDriveId,
                'includeItemsFromAllDrives' => true,
                'supportsAllDrives'         => true,
                'includeRemoved'            => true,
                'pageSize'                  => 1000,
                'fields'                    => 'nextPageToken, newStartPageToken, '
                    . 'changes(changeType,fileId,removed,time,file(' . self::FILE_FIELDS . '))',
            ]);

            foreach ($response->getChanges() as $change) {
                // The feed also carries changes to the drive itself — a rename, a new
                // restriction — with no file behind them. Not a document change, so skipped.
                if ($change->getChangeType() === 'drive' || !$change->getFileId()) {
                    continue;
                }

                $changes[] = $this->mapChange($change);
            }

            $nextPage  = $response->getNextPageToken();
            $pageToken = $nextPage !== null && $nextPage !== '' ? $nextPage : null;
            $fresh     = $response->getNewStartPageToken();
            $next      = $fresh !== null && $fresh !== '' ? $fresh : $next;
        } while ($pageToken !== null);

        if ($next === null) {
            throw new UnexpectedDriveStateException(
                'Google ended the change list without a new token, so there is nowhere to '
                . 'resume from. Reusing the old one would replay the same changes for ever.'
            );
        }

        return new DriveChanges($changes, $next);
    }

    /**
     * Lock an item against editing, with the reason Google shows whoever tries.
     *
     * For a document that is finished — an approved report, a signed act — where the point is
     * that nobody edits it by accident afterwards. The service user can lift it again.
     */
    public function lock(string $fileId, ?string $reason = null): DriveDocument
    {
        return $this->restrictContent($fileId, true, $reason);
    }

    /** Release a locked item so it can be edited again. */
    public function unlock(string $fileId): DriveDocument
    {
        return $this->restrictContent($fileId, false, null);
    }

    /**
     * The versions Drive kept of an item, oldest first.
     *
     * **The list can be incomplete.** Google's own documentation says older revisions are
     * omitted for files with a long history — frequently edited Sheets and Docs especially —
     * and that the Workspace editor may show more than the API does. Pin what matters with
     * keepRevision(); this is a recovery aid, not an audit trail.
     *
     * @return DriveRevision[]
     */
    public function listRevisions(string $fileId): array
    {
        $this->assertAccess($fileId);

        $revisions = [];
        $pageToken = null;
        $pages     = 0;

        do {
            $this->assertPageBudget(++$pages, 'revisions.list');

            $params = [
                'fields'   => 'nextPageToken, revisions(' . self::REVISION_FIELDS . ')',
                'pageSize' => 1000,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->drive->revisions->listRevisions($fileId, $params);

            foreach ($response->getRevisions() as $revision) {
                $revisions[] = $this->mapRevision($revision);
            }

            $next      = $response->getNextPageToken();
            $pageToken = $next !== null && $next !== '' ? $next : null;
        } while ($pageToken !== null);

        return $revisions;
    }

    public function revision(string $fileId, string $revisionId): DriveRevision
    {
        $this->assertAccess($fileId);

        return $this->mapRevision($this->drive->revisions->get($fileId, $revisionId, [
            'fields' => self::REVISION_FIELDS,
        ]));
    }

    /**
     * Pin a version so Drive keeps it however the history is pruned, or release it again.
     *
     * Google prunes revisions on its own and allows only a limited number of pinned ones per
     * file, so this is the way to make sure the version someone will want later survives.
     *
     * Only an uploaded file's revisions can be pinned. On a Google format — a Sheet, a Doc —
     * Drive accepts the call, ignores it, and answers with the revision unchanged, so the
     * returned `keptForever` is the thing to read rather than the absence of an exception.
     * Verified against Drive, not inferred: a spreadsheet answers false where an uploaded
     * file answers true. Those formats keep their history for the editor alone, which is the
     * same reason deleteRevision() cannot touch them.
     */
    public function keepRevision(string $fileId, string $revisionId, bool $forever = true): DriveRevision
    {
        $this->assertAccess($fileId);

        $payload = new GoogleRevision();
        $payload->setKeepForever($forever);

        $revision = $this->mapRevision($this->drive->revisions->update($fileId, $revisionId, $payload, [
            'fields' => self::REVISION_FIELDS,
        ]));

        $this->dispatch(new RevisionKeptEvent($fileId, $revisionId, $forever));

        return $revision;
    }

    /**
     * Remove a version from an item's history. There is no trash for a revision: what that
     * version held is gone.
     *
     * Drive refuses two things here, and both arrive as its own 403 rather than being
     * second-guessed: the current version of any file, and any version of a Google format —
     * Docs and Sheets keep their history for the editor alone. Only the older versions of an
     * uploaded file can be deleted, which is also where the storage they take up matters.
     */
    public function deleteRevision(string $fileId, string $revisionId): void
    {
        $this->assertAccess($fileId);

        $this->drive->revisions->delete($fileId, $revisionId);

        $this->dispatch(new RevisionDeletedEvent($fileId, $revisionId));
    }

    /**
     * The content of an old version, as a download.
     *
     * There is no way to make an old version current again — Drive API v3 lists, reads, pins
     * and deletes revisions, and only the Google editor restores one in place. So recovering
     * old content means fetching it and deciding what to do with it: import() it as a new
     * document, or read the values back into the live one with SpreadsheetService.
     *
     * A Google format has no stored bytes, so its revisions carry export links instead and
     * $mimeType picks between them — required when there is more than one to pick from,
     * since Google's order is not a contract. An uploaded file has bytes, and they come back
     * as they are whatever is asked for.
     *
     * @throws \InvalidArgumentException for a format the revision does not offer, or none
     *                                   named where several are offered
     */
    public function exportRevision(string $fileId, string $revisionId, ?string $mimeType = null): DriveExport
    {
        $revision = $this->revision($fileId, $revisionId);
        $target   = null;

        // Settled before the document is fetched for its name: a format this revision does
        // not offer is a caller's mistake, and it should not cost a round trip to say so.
        if ($revision->exportLinks !== []) {
            // One link means one possible answer. Several mean the caller has to choose:
            // Google's order is not a contract, and "the first" would change under them.
            if ($mimeType === null && count($revision->exportLinks) > 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Revision "%s" is offered in several formats, so say which one: %s.',
                    $revisionId,
                    implode(', ', array_keys($revision->exportLinks))
                ));
            }

            $target = $mimeType ?? array_key_first($revision->exportLinks);

            if (!isset($revision->exportLinks[$target])) {
                throw new \InvalidArgumentException(sprintf(
                    'Revision "%s" is not offered as %s. Available: %s.',
                    $revisionId,
                    $target,
                    implode(', ', array_keys($revision->exportLinks))
                ));
            }
        }

        $name = $this->get($fileId)->name ?? $fileId;

        if ($target !== null) {
            return new DriveExport(
                $this->exportFilename($name, $target),
                $target,
                $this->fetchAuthorized($revision->exportLinks[$target])
            );
        }

        // An uploaded file keeps its bytes, so the revision itself can be downloaded.
        return new DriveExport(
            $name,
            $revision->mimeType ?? self::FALLBACK_MIME,
            $this->bodyOf($this->drive->revisions->get($fileId, $revisionId, ['alt' => 'media']))
        );
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
                'fields'            => 'nextPageToken, permissions(id,emailAddress,role,type,displayName,expirationTime,permissionDetails)',
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
        string $type = DrivePermission::TYPE_USER,
        ?\DateTimeInterface $expiresAt = null
    ): DrivePermission {
        // Local validation first: a bad role must not cost a walk up the parent chain.
        $this->assertRole($role);
        $this->assertType($type);
        $this->assertAccess($fileId);

        return $this->share($fileId, $email, $role, $type, $expiresAt);
    }

    /**
     * The sharing call itself, on already validated input. Callers decide whether the
     * viewer's access had to be proven first.
     */
    private function share(
        string $fileId,
        string $email,
        string $role,
        string $type,
        ?\DateTimeInterface $expiresAt = null
    ): DrivePermission {
        $payload = new GooglePermission([
            'type'         => $type,
            'role'         => $role,
            'emailAddress' => $email,
        ]);

        if ($expiresAt !== null) {
            $payload->setExpirationTime($this->expiryFor($expiresAt));
        }

        $created = $this->drive->permissions->create($fileId, $payload, [
            'supportsAllDrives'     => true,
            'sendNotificationEmail' => $this->notifyOnShare,
            'fields'                => 'id,emailAddress,role,type,displayName,expirationTime',
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
        string $type = DrivePermission::TYPE_USER,
        ?\DateTimeInterface $expiresAt = null
    ): DrivePermission {
        $this->assertRole($role);
        $this->assertType($type);

        return $this->share($fileId, $email, $role, $type, $expiresAt);
    }

    /**
     * Share with a Google group — the usual way to give a whole team access at once.
     */
    public function grantToGroup(
        string $fileId,
        string $groupEmail,
        string $role = DrivePermission::ROLE_WRITER,
        ?\DateTimeInterface $expiresAt = null
    ): DrivePermission {
        return $this->grant($fileId, $groupEmail, $role, DrivePermission::TYPE_GROUP, $expiresAt);
    }

    /**
     * Give an existing grant an expiry, or lift the one it has.
     *
     * The way to extend a contractor's access without re-sharing, and the way to make a
     * lasting grant temporary after the fact. Reported through AccessGrantedEvent either way,
     * carrying the grant as it now stands — a listener that keeps an audit trail sees one
     * event per change to a grant, not one per grant.
     *
     * Costs one extra call: Drive demands the role in the body of a permissions.update and
     * refuses the whole thing without it, so the grant is read first and its own role sent
     * back unchanged. Reading it rather than taking it from the caller is deliberate — a role
     * passed in from a stale DrivePermission would quietly change what someone may do, which
     * is a far worse outcome than a round trip on an operation this rare.
     *
     * On a folder, only a reader's grant may expire. Drive refuses a writer's or a commenter's
     * with a 403 whose reason is `cannotSetExpiration`, and it says only "Expiration dates
     * cannot be set on this item" — the item is fine, it is the pairing it objects to. A file's
     * grant may expire in any of the three roles. Measured against Drive, not inferred.
     *
     * @throws InheritedPermissionException when the grant lives on a parent folder
     */
    public function setExpiry(string $fileId, string $permissionId, ?\DateTimeInterface $expiresAt): DrivePermission
    {
        $this->assertAccess($fileId);

        $current = $this->drive->permissions->get($fileId, $permissionId, [
            'supportsAllDrives' => true,
            'fields'            => 'id,role',
        ]);

        $role = $current->getRole();

        if ($role === null || $role === '') {
            throw new UnexpectedDriveStateException(sprintf(
                'Drive described permission "%s" on "%s" without a role, so its expiry cannot be '
                . 'changed without guessing what the grant allows.',
                $permissionId,
                $fileId
            ));
        }

        $payload = new GooglePermission();
        // Drive rejects an update with no role — 400, "The permission role field is required" —
        // however little of the grant is being changed. The role it already has goes back as it is.
        $payload->setRole($role);

        $params = [
            'supportsAllDrives' => true,
            'fields'            => 'id,emailAddress,role,type,displayName,expirationTime',
        ];

        if ($expiresAt === null) {
            // A JSON null in the body does not lift an expiry — Drive answers with the old time
            // still on the grant, so the access would go on ending at a date the caller cleared.
            // This parameter is the only thing that lifts one, and it must not travel when an
            // expiry is being set or Drive would drop it again.
            $params['removeExpiration'] = true;
        } else {
            $payload->setExpirationTime($this->expiryFor($expiresAt));
        }

        try {
            $updated = $this->drive->permissions->update($fileId, $permissionId, $payload, $params);
        } catch (GoogleServiceException $e) {
            if ($this->hasReason($e, ['cannotDeletePermission', 'cannotModifyInheritedPermission'])) {
                throw new InheritedPermissionException(
                    'This permission is inherited from a parent folder. Change its expiry on that folder instead.'
                );
            }

            throw $e;
        }

        $this->forgetGrants($fileId);

        $permission = $this->mapPermission($updated);
        $this->dispatch(new AccessGrantedEvent($fileId, $permission));

        return $permission;
    }

    /**
     * The Unix time a grant runs out, or null for a lasting one or a time Drive sent in a
     * form that cannot be read — which is treated as lasting rather than as expired, since
     * hiding a document over a parsing quirk would be the worse mistake.
     */
    private function expiryTimestamp(?string $rfc3339): ?int
    {
        if ($rfc3339 === null || $rfc3339 === '') {
            return null;
        }

        $timestamp = strtotime($rfc3339);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * An expiry in the format Drive wants, checked against the limits it documents: the time
     * must be in the future and no more than a year ahead. Both are Google's own rules, and
     * a 400 from them names neither the value nor the grant.
     */
    private function expiryFor(\DateTimeInterface $expiresAt): string
    {
        $now = new \DateTimeImmutable();

        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException(sprintf(
                'An expiry has to be in the future; %s is not.',
                $expiresAt->format(\DateTimeInterface::RFC3339)
            ));
        }

        if ($expiresAt > $now->modify('+1 year')) {
            throw new \InvalidArgumentException(sprintf(
                'Google takes an expiry no more than a year ahead; %s is further.',
                $expiresAt->format(\DateTimeInterface::RFC3339)
            ));
        }

        return $expiresAt->format(\DateTimeInterface::RFC3339);
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
     * The application's own metadata on an item.
     *
     * Drive keeps these private to the OAuth client that wrote them, which makes them the
     * place to record what an item *is* in your domain — the order it belongs to, the contract
     * it was generated from — without a table of your own mapping ids to file ids.
     *
     * Not part of DriveDocument: a listing would carry them on every row for the sake of the
     * few callers that look, so this is an explicit read.
     *
     * @return array<string, string>
     */
    public function appProperties(string $fileId): array
    {
        $this->assertAccess($fileId);

        $file = $this->drive->files->get($fileId, [
            'supportsAllDrives' => true,
            'fields'            => 'id,appProperties',
        ]);

        /** @var array<string, string>|null $properties */
        $properties = $file->getAppProperties();

        return $properties ?? [];
    }

    /**
     * Merge metadata into an item. Keys not mentioned are left alone; a null value removes
     * the key, which is how Drive itself expresses a deletion.
     *
     * Values are stored as strings — that is all Drive keeps — so an int arrives back as its
     * decimal text and a bool as "1" or "". Compare accordingly.
     *
     * Drive caps a property at 124 bytes for key and value together, and 100 properties per
     * item. Those limits are Google's and stay enforced by Google: an oversized set comes back
     * as a Drive error rather than being second-guessed here, so a limit changing on their
     * side does not need a release on this one.
     *
     * Returns nothing on purpose: appProperties are not part of DriveDocument, so handing
     * one back would show a document that does not carry the change just made. Read them
     * with appProperties() when you need them.
     *
     * @param array<array-key, string|int|float|bool|null> $properties Keys are cast to string:
     *        PHP turns a key like "2024" into the integer 2024 before this ever sees it, so a
     *        stricter annotation would reject callers this method handles correctly
     */
    public function setAppProperties(string $fileId, array $properties): void
    {
        $this->assertAccess($fileId);

        $payload = [];

        // The casts are not redundant however the array is annotated: PHP turns a key like
        // "2024" into the integer 2024 on the way in, and appProperties keys come from callers.
        foreach ($properties as $key => $value) {
            if (trim((string) $key) === '') {
                throw new \InvalidArgumentException('A property key cannot be empty.');
            }

            $payload[(string) $key] = $value === null ? null : (string) $value;
        }

        if ($payload === []) {
            return;
        }

        $this->drive->files->update($fileId, new DriveFile(['appProperties' => $payload]), [
            'supportsAllDrives' => true,
            'fields'            => 'id',
        ]);

        $this->dispatch(new DocumentPropertiesChangedEvent($fileId, $payload));
    }

    /**
     * Every item carrying the given metadata, filtered for the current viewer.
     *
     * This is the other half of setAppProperties(): ask Drive for "the spreadsheet belonging
     * to order 4711" instead of keeping that mapping yourself.
     *
     * @return DriveDocument[]
     */
    public function findByAppProperty(string $key, string $value): array
    {
        [$q, $filter] = $this->propertyCriteria($key, $value);

        return $this->queryAll($q, $filter);
    }

    /** One page of findByAppProperty(). */
    public function findByAppPropertyPage(
        string $key,
        string $value,
        ?string $pageToken = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): DrivePage {
        [$q, $filter] = $this->propertyCriteria($key, $value);

        return $this->queryPage($q, $filter, $pageToken, $pageSize);
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

            foreach ($this->grantsFor($file, $identities, true) as $role) {
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

        $escaped = $this->escapeQueryValue($name);

        return [
            sprintf("%s and name contains '%s' and trashed=false", $this->typeFilter(), $escaped),
            !$this->viewerContext->seesEverything(),
        ];
    }

    /**
     * Key and value are interpolated into the Drive query, so both are escaped.
     *
     * @return array{0: string, 1: bool}
     */
    private function propertyCriteria(string $key, string $value): array
    {
        $this->assertConfigured();

        if (trim($key) === '') {
            throw new \InvalidArgumentException('A property key cannot be empty.');
        }

        return [
            sprintf(
                "%s and appProperties has { key='%s' and value='%s' } and trashed=false",
                $this->typeFilter(),
                $this->escapeQueryValue($key),
                $this->escapeQueryValue($value)
            ),
            !$this->viewerContext->seesEverything(),
        ];
    }

    /** Backslashes and quotes, the two characters that can end a Drive query early. */
    private function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
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
        $now       = time();
        // The soonest a grant seen here runs out, if any does: the cache entry must not
        // outlive it, or the viewer keeps their access for as long as the TTL pretends.
        $soonest = null;

        try {
            do {
                $this->assertPageBudget(++$pages, 'permissions.list');

                $params = [
                    'supportsAllDrives' => true,
                    'fields'            => 'nextPageToken, permissions(emailAddress,type,role,expirationTime)',
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

                    $expires = $this->expiryTimestamp($permission->getExpirationTime());

                    // Google removes an expired grant eventually, not instantly. Until it
                    // does, the list may still carry it; it opens nothing here.
                    if ($expires !== null && $expires <= $now) {
                        continue;
                    }

                    if ($expires !== null) {
                        $soonest = $soonest === null ? $expires : min($soonest, $expires);
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
            $lifetime = $this->permissionCacheTtl;

            if ($soonest !== null) {
                $lifetime = max(1, min($lifetime, $soonest - $now));
            }

            $item->set($grants);
            $item->expiresAfter($lifetime);
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
     * A boolean answer can stop at the first match, but the strongest role cannot: the
     * permissions Google embeds in a file are not always the whole list, so asking for
     * every grant means reading both sources.
     *
     * @param string[] $identities normalised e-mail plus the viewer group addresses
     * @return list<string> one role per matching grant, unranked
     */
    private function grantsFor(DriveFile $file, array $identities, bool $everyGrant = false): array
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

        if ($roles !== [] && !$everyGrant) {
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
        // A collection field, so empty rather than null when the item is not locked.
        $locked   = $file->getContentRestrictions()[0] ?? null;

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
            (bool) $locked?->getReadOnly(),
            $locked?->getReadOnly() === true ? $locked->getReason() : null,
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

    private function restrictContent(string $fileId, bool $readOnly, ?string $reason): DriveDocument
    {
        $this->assertAccess($fileId);

        $restriction = new ContentRestriction();
        $restriction->setReadOnly($readOnly);

        if ($readOnly && $reason !== null) {
            $restriction->setReason($reason);
        }

        $payload = new DriveFile();
        $payload->setContentRestrictions([$restriction]);

        $document = $this->mapFile($this->drive->files->update($fileId, $payload, [
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]));

        $this->dispatch(new DocumentLockChangedEvent($document, $readOnly, $readOnly ? $reason : null));

        return $document;
    }

    private function mapChange(GoogleChange $change): DriveChange
    {
        $file    = $change->getFile();
        $removed = (bool) $change->getRemoved();

        // A change that is not a removal drops the cached sharing of that item: whatever
        // happened to it in Drive, what we remember about who can see it is now a guess.
        if (!$removed) {
            $this->forgetGrants((string) $change->getFileId());
        }

        return new DriveChange(
            (string) $change->getFileId(),
            $removed,
            $change->getTime(),
            $removed || $file === null ? null : $this->mapFile($file),
        );
    }

    private function mapRevision(GoogleRevision $revision): DriveRevision
    {
        $user = $revision->getLastModifyingUser();
        $size = $revision->getSize();

        /** @var array<string, string>|null $links */
        $links = $revision->getExportLinks();

        return new DriveRevision(
            (string) $revision->getId(),
            $revision->getModifiedTime(),
            $user !== null ? ($user->getDisplayName() ?: $user->getEmailAddress()) : null,
            // Drive sends int64 as a string, and omits it for its own formats.
            $size !== null ? (int) $size : null,
            (bool) $revision->getKeepForever(),
            $revision->getMimeType(),
            $revision->getOriginalFilename(),
            $links ?? [],
        );
    }

    /**
     * Fetches a Drive URL with the service user's credentials.
     *
     * Export links are ordinary authenticated URLs rather than API calls, so they go through
     * the client's own authorised HTTP client instead of a service method.
     */
    private function fetchAuthorized(string $url): StreamInterface
    {
        $response = $this->drive->getClient()->authorize()->request('GET', $url, ['stream' => true]);

        // That client is built with http_errors off, so an error page would stream back as
        // if it were the document. Turned into the same exception a service call would raise.
        if ($response->getStatusCode() >= 400) {
            $body   = (string) $response->getBody();
            $decoded = json_decode($body, true);
            $error   = is_array($decoded) ? ($decoded['error'] ?? []) : [];

            throw new GoogleServiceException(
                is_array($error) && isset($error['message']) && is_string($error['message'])
                    ? $error['message']
                    : sprintf('Fetching %s answered HTTP %d.', $url, $response->getStatusCode()),
                $response->getStatusCode(),
                null,
                is_array($error) && isset($error['errors']) && is_array($error['errors']) ? $error['errors'] : []
            );
        }

        return $response->getBody();
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
            $permission->getExpirationTime(),
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

    /** One request, for a file small enough that holding it in memory is fine. */
    private function uploadAtOnce(string $path, DriveFile $metadata, string $uploadMime): DriveFile
    {
        // Bounded read: a stat that failed above must not let an arbitrarily large file through.
        $contents = file_get_contents($path, false, null, 0, self::MULTIPART_LIMIT + 1);

        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Could not read the file to import: "%s".', $path));
        }

        if (strlen($contents) > self::MULTIPART_LIMIT) {
            // Only reachable when the stat was wrong or the file grew. These bytes are not
            // the whole file, so its size has to be measured rather than taken from them:
            // a resumable session declares the total up front and Google rejects the first
            // chunk that runs past it.
            return $this->uploadResumable($path, $metadata, $uploadMime, $this->measureSize($path));
        }

        // What was read is the last word on how big this is, whatever the stat claimed.
        $this->assertUploadSize(strlen($contents));

        return $this->drive->files->create($metadata, [
            'data'              => $contents,
            'mimeType'          => $uploadMime,
            'uploadType'        => 'multipart',
            'supportsAllDrives' => true,
            'fields'            => self::FILE_FIELDS,
        ]);
    }

    /**
     * Drive's resumable protocol: open a session, then send the bytes a chunk at a time.
     *
     * The client has to be told to hand back the request instead of running it, and that flag
     * is global to the client — leaving it on would turn every later call anywhere in the
     * application into a Request object instead of a result. Hence the finally.
     */
    private function uploadResumable(string $path, DriveFile $metadata, string $uploadMime, int $size): DriveFile
    {
        $this->assertUploadSize($size);

        $client = $this->drive->getClient();
        $handle = false;

        $client->setDefer(true);

        try {
            // While the client is deferred this hands back the request instead of running
            // it. The generated signature of files->create() says DriveFile and cannot say
            // that, so the type is checked rather than asserted: were the flag somehow not
            // in effect, MediaFileUpload would fail much further from the cause.
            /** @var mixed $deferred */
            $deferred = $this->drive->files->create($metadata, [
                'supportsAllDrives' => true,
                'fields'            => self::FILE_FIELDS,
            ]);

            if (!$deferred instanceof RequestInterface) {
                throw new \LogicException(sprintf(
                    'The Google client was asked to defer the upload but answered with %s '
                    . 'instead of a request, so the resumable upload cannot start.',
                    get_debug_type($deferred)
                ));
            }

            $media = new MediaFileUpload($client, $deferred, $uploadMime, '', true, $this->chunkBytes);
            $media->setFileSize($size);

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new \InvalidArgumentException(sprintf('Could not open the file to import: "%s".', $path));
            }

            $status = false;
            $sent   = 0;

            // Driven by the declared total rather than feof(): that is the number Google
            // measures the chunks against, and a file that shrank underneath us must fail
            // rather than open a session it can never fill.
            while ($status === false && $sent < $size) {
                $take = min($this->chunkBytes, $size - $sent);

                // Never leave a single byte for the last chunk: the Google client asks
                // `false == $chunk`, and PHP reads the one-byte string "0" as false, so such
                // a chunk would be dropped and the upload stall one byte from done.
                //
                // Swallow that byte instead of shortening this chunk. Only the *last* chunk
                // may be any size — every earlier one has to be a multiple of the
                // granularity, which is the rule chunkBytes itself is validated against —
                // and taking the extra byte is what makes this one the last.
                if ($size - $sent - $take === 1) {
                    ++$take;
                }

                $chunk = fread($handle, $take);

                if ($chunk === false || $chunk === '') {
                    throw new UnexpectedDriveStateException(sprintf(
                        'Reading "%s" stopped part way through the upload.',
                        $path
                    ));
                }

                $sent  += strlen($chunk);
                $status = $media->nextChunk($chunk);
            }

            if (!$status instanceof DriveFile) {
                throw new UnexpectedDriveStateException(sprintf(
                    'The upload of "%s" ended without Google describing the file it created.',
                    basename($path)
                ));
            }

            return $status;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $client->setDefer(false);
        }
    }

    /**
     * The size of a file the stat could not describe, taken from the stream instead.
     *
     * Seeking to the end is the cheapest honest answer: it reads nothing, and a stream that
     * cannot even do that cannot feed a resumable upload either, which has to know the total
     * before it sends the first chunk.
     */
    private function measureSize(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \InvalidArgumentException(sprintf('Could not open the file to import: "%s".', $path));
        }

        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new UnexpectedDriveStateException(sprintf(
                    'The size of "%s" cannot be established: its stat says nothing and the stream '
                    . 'will not seek. A resumable upload has to declare the total up front, so copy '
                    . 'the file somewhere seekable and import that.',
                    $path
                ));
            }

            $size = ftell($handle);

            if ($size === false) {
                throw new UnexpectedDriveStateException(sprintf(
                    'The size of "%s" cannot be established: the stream would not say where its end is.',
                    $path
                ));
            }

            return $size;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The application's own ceiling, when it set one. Drive itself takes files up to 5 TB.
     *
     * @throws UploadTooLargeException
     */
    private function assertUploadSize(int $bytes): void
    {
        if ($this->maxUploadBytes > 0 && $bytes > $this->maxUploadBytes) {
            throw new UploadTooLargeException(sprintf(
                'This file is %d bytes and this application accepts at most %d.',
                $bytes,
                $this->maxUploadBytes
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

        // It is interpolated into every Drive query, so it is held to the same alphabet as
        // any other id — checked once here rather than trusted because it came from config.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $this->sharedDriveId) !== 1) {
            throw new NotConfiguredException(sprintf(
                'shared_drive_id "%s" is not a Google Drive id.',
                $this->sharedDriveId
            ));
        }
    }
}
