# Google Drive Docs Bundle

[![Latest version](https://img.shields.io/packagist/v/borsche/google-drive-docs-bundle.svg)](https://packagist.org/packages/borsche/google-drive-docs-bundle)
[![CI](https://github.com/YlikScherbak/google-drive-docs-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/YlikScherbak/google-drive-docs-bundle/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/borsche/google-drive-docs-bundle/php.svg)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/symfony-6.x%20%7C%207.x-000000.svg)](https://symfony.com/)
[![Downloads](https://img.shields.io/packagist/dt/borsche/google-drive-docs-bundle.svg)](https://packagist.org/packages/borsche/google-drive-docs-bundle)
[![License](https://img.shields.io/packagist/l/borsche/google-drive-docs-bundle.svg)](LICENSE)

A Symfony bundle that turns a **Google Shared Drive into a document workspace inside your own application**.

Your users browse folders, create and edit real Google Sheets/Docs, and manage who has access — without ever leaving your UI. The documents themselves stay in Google, so formulas, formatting, charts and real-time collaboration keep working exactly as users expect.

## Why this exists

Spreadsheets with working formulas are hard to rebuild. You can either:

1. build your own spreadsheet engine (expensive, never fully compatible), or
2. keep the documents in Google and make your application the **window and the control panel**.

This bundle implements the second option: file management, folder navigation, sharing and per-user visibility are handled by your app; editing is handled by Google's own editor embedded in an `iframe`.

## Features

- **Browse** the Shared Drive: folders and documents, folder-first ordering
- **Navigate** into folders (any depth) and **search** by name across the whole drive
- **Paginated listings** — one Google call per page, so a drive with thousands of files stays fast
- **Create / rename / move** documents and folders
- **Duplicate documents and instantiate templates** — formulas, sheets and formatting come along
- **Export** to XLSX, CSV, PDF, DOCX and more, streamed straight to the browser
- **Import** an `.xlsx`/`.csv`/`.docx` upload and have Google convert it into an editable document
- **Trash and restore** items, browse the trash, or erase for good — accidental deletions stay recoverable
- **Embed the native Google editor** via the `webViewLink` of each document
- **Manage sharing**: list, grant and revoke access per file or folder (`reader` / `commenter` / `writer`), for individual users or **Google groups**
- **Per-user visibility**: users only see the items shared with them; administrators see everything
- **Capability-aware**: every item reports what Google will actually allow, so the UI can draw only the buttons that work
- **Inheritance-aware**: sharing a folder cascades to its whole subtree, and inherited permissions are flagged so your UI can hide a "remove" button that Google would reject
- **OAuth-based auth** — works on organisations where service-account keys are disabled by policy
- **Retries with exponential backoff** on rate limits and transient Google faults
- **PSR-14 events** on every write, so auditing and notifications stay in your application

## Requirements

- PHP 8.1+
- Symfony 6.x or 7.x
- A Google Workspace plan that includes **Shared Drives** (Business Standard or higher)

## Installation

```bash
composer require borsche/google-drive-docs-bundle
```

Register the bundle (Symfony Flex does it automatically):

```php
// config/bundles.php
return [
    // ...
    Borsche\GoogleDriveDocsBundle\GoogleDriveDocsBundle::class => ['all' => true],
];
```

## Google setup

Do this once, with an administrator account of your Workspace domain.

### 1. Create a project and enable the APIs

1. Open [Google Cloud Console](https://console.cloud.google.com/) and create a project.
2. **APIs & Services → Library** → enable **Google Drive API** and **Google Sheets API**.

### 2. Create an OAuth client

1. **APIs & Services → OAuth consent screen** → user type **Internal**.
   Internal apps may use restricted Drive scopes without going through Google's app verification.
2. **Credentials → Create credentials → OAuth client ID** → application type **Desktop app**.
3. Keep the **Client ID** and **Client secret**.

> Service-account JSON keys are deliberately not used: Google blocks their creation by default on new
> organisations (`iam.managed.disableServiceAccountKeyCreation`). OAuth needs no policy changes.

### 3. Create the Shared Drive

1. In [Google Drive](https://drive.google.com/) → **Shared drives** → **New**.
2. Add the service user (the account you will authorise in the next step) as **Content manager**.
   Use **Manager** instead if you want `deleteForever()` to work: Google lets only a Manager
   erase items for good, and a Content manager may just move them to the trash.
3. Copy the drive ID from the URL: `drive.google.com/drive/folders/<SHARED_DRIVE_ID>`.

### 4. Obtain a refresh token

```bash
# Step 1 — prints the consent URL
bin/console google-drive-docs:authorize

# open the URL as the service user, approve, copy the "code" query parameter, then:
bin/console google-drive-docs:authorize "<code>"
```

The command prints a refresh token. Store it as a secret — it is what lets the bundle talk to Google.

## Configuration

```yaml
# config/packages/google_drive_docs.yaml
google_drive_docs:
    client_id: '%env(GOOGLE_DRIVE_DOCS_CLIENT_ID)%'
    client_secret: '%env(GOOGLE_DRIVE_DOCS_CLIENT_SECRET)%'
    refresh_token: '%env(GOOGLE_DRIVE_DOCS_REFRESH_TOKEN)%'
    shared_drive_id: '%env(GOOGLE_DRIVE_DOCS_SHARED_DRIVE_ID)%'

    # Optional. Which Google MIME types count as documents (folders are always included).
    document_mime_types:
        - 'application/vnd.google-apps.spreadsheet'
        # - 'application/vnd.google-apps.document'
        # - 'application/vnd.google-apps.presentation'

    # Optional. Caches sharing lookups used by visibility filtering.
    # Null pool disables caching entirely.
    permission_cache:
        pool: 'cache.app'
        ttl: 300

    # Optional. Exponential backoff on rate limits and transient Google faults.
    retry:
        attempts: 3          # extra tries after the first failure; 0 disables retrying
        initial_delay: 1.0   # seconds before the first retry, doubling on each further one
        max_delay: 60.0      # upper bound for a single wait

    # Optional. Send Google notification e-mails when granting access.
    # Required if you share with addresses that have no Google account.
    notify_on_share: false
```

## Usage

```php
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;

public function __construct(private readonly DriveDocumentService $drive) {}

// Browsing
$rootItems   = $this->drive->listFolder();            // root of the Shared Drive
$folderItems = $this->drive->listFolder($folderId);   // inside a folder
$found       = $this->drive->search('price list');    // by name, whole drive

// Paginated — one Google call per page
$page = $this->drive->listFolderPage($folderId, $token);
$page = $this->drive->searchPage('price list', $token);
$page = $this->drive->listTrashPage($token);

// Documents and folders
$doc    = $this->drive->createDocument('Q3 report', $folderId);
$folder = $this->drive->createFolder('Portugal');
$this->drive->rename($doc->id, 'Q3 report (final)');
$this->drive->move($doc->id, $otherFolderId);         // null → move to root

// Copying and templates
$duplicate = $this->drive->copy($doc->id);                            // beside the original
$duplicate = $this->drive->copy($doc->id, 'Q4 report', $folderId);    // renamed, elsewhere
$invoice   = $this->drive->createFromTemplate($templateId, 'Invoice #4711', $folderId);

// Export (streamed, never buffered)
$export = $this->drive->export($doc->id, DriveExport::XLSX);
$export->filename;               // "Q3 report.xlsx"
$export->mimeType;               // the format you actually got
$export->contentDisposition();   // ready for the response header

// Import (an .xlsx becomes an editable Google Sheet)
$sheet = $this->drive->import($uploadedFile->getPathname(), null, $folderId);
$asIs  = $this->drive->import('/tmp/scan.pdf', 'Contract scan', $folderId, convert: false);

// Trash
$this->drive->trash($doc->id);                        // recoverable
$trashed = $this->drive->listTrash();                 // whole drive, viewer-filtered
$this->drive->restore($doc->id);
$this->drive->deleteForever($doc->id);                // irreversible, needs the Manager role

// Sharing
$permissions = $this->drive->listPermissions($doc->id);
$this->drive->grant($folder->id, 'user@example.com', 'writer');
$this->drive->grantToGroup($folder->id, 'portugal@example.com', 'writer'); // whole team at once
$this->drive->revoke($doc->id, $permissionId);

// Embed in your UI
echo $doc->webViewLink; // https://docs.google.com/spreadsheets/d/<id>/edit
```

Every item is returned as a `DriveDocument` (`id`, `name`, `mimeType`, `webViewLink`, `modifiedTime`, `type`, `trashed`, `createdTime`, `size`, `iconLink`, `thumbnailLink`, `lastModifiedBy`, `capabilities`) and every sharing entry as a `DrivePermission` (`id`, `emailAddress`, `role`, `type`, `displayName`, `inherited`, `inheritedFrom`). Both expose `toArray()` for JSON responses.

## Per-user visibility

By default everyone sees the whole drive. To restrict visibility to what each user has been granted, implement `ViewerContextInterface`:

```php
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class AppViewerContext implements ViewerContextInterface
{
    public function __construct(private readonly Security $security) {}

    public function getViewerEmail(): ?string
    {
        return $this->security->getUser()?->getGoogleEmail();
    }

    public function seesEverything(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    /**
     * Google groups the user belongs to. Return [] if you share with individuals only.
     *
     * @return string[]
     */
    public function getViewerGroups(): array
    {
        return $this->security->getUser()?->getGoogleGroups() ?? [];
    }
}
```

```yaml
# config/services.yaml
services:
    Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface: '@App\Drive\AppViewerContext'
```

With that in place:

- the **root** lists only the items shared directly with the user;
- entering a **folder** they have access to shows its entire contents;
- opening or modifying anything else throws `AccessDeniedException` (map it to HTTP 403).

A typical layout is one folder per team or country: share `Portugal` with the Portuguese team and they see that folder and everything inside it — and nothing else.

### Sharing with groups

Granting access to a Google group is usually easier than listing people one by one: membership
is then managed in Google, not in your application.

```php
$this->drive->grantToGroup($portugalFolder->id, 'portugal@example.com');
```

Google does not expose group membership through the Drive API, so the bundle asks your
application instead: return the user's group addresses from `getViewerGroups()` and items
shared with any of those groups become visible to them.

## Events

Every write operation dispatches a PSR-14 event, so auditing, notifications or cache
invalidation live in your application instead of in the bundle:

| Event | Dispatched when | Carries |
|---|---|---|
| `DocumentCreatedEvent` | a document is created | `document`, `parentId` |
| `FolderCreatedEvent` | a folder is created | `folder`, `parentId` |
| `DocumentCopiedEvent` | a document is duplicated or built from a template | `document`, `sourceId`, `parentId` |
| `DocumentImportedEvent` | a file is uploaded into the drive | `document`, `originalFilename`, `parentId` |
| `DocumentRenamedEvent` | an item is renamed | `document` |
| `DocumentMovedEvent` | an item is moved | `document`, `fromParentId`, `toParentId` |
| `DocumentTrashedEvent` | an item is moved to the trash | `document` |
| `DocumentRestoredEvent` | an item is taken out of the trash | `document` |
| `DocumentDeletedEvent` | an item is erased for good | `fileId` |
| `AccessGrantedEvent` | access is granted | `fileId`, `permission` |
| `AccessRevokedEvent` | access is revoked | `fileId`, `permissionId` |

All of them extend `DriveEvent` and expose `fileId`. Read operations dispatch nothing.

```php
use Borsche\GoogleDriveDocsBundle\Event\AccessGrantedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class AuditDriveActivity
{
    public function __construct(private readonly LoggerInterface $auditLog) {}

    public function __invoke(AccessGrantedEvent $event): void
    {
        $this->auditLog->info('drive.access_granted', [
            'file'  => $event->fileId,
            'email' => $event->permission->emailAddress,
            'role'  => $event->permission->role,
        ]);
    }
}
```

The dispatcher is optional: without one (or when the bundle is used outside Symfony)
the service simply skips dispatching.

## Example controller

```php
#[Route('/api/documents')]
class DocumentController extends AbstractController
{
    public function __construct(private readonly DriveDocumentService $drive) {}

    #[Route('', methods: 'GET')]
    public function list(Request $request): JsonResponse
    {
        $search = $request->query->get('search');

        $items = $search
            ? $this->drive->search($search)
            : $this->drive->listFolder($request->query->get('parentId'));

        return $this->json(array_map(fn ($d) => $d->toArray(), $items));
    }

    #[Route('', methods: 'POST')]
    public function create(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        return $this->json(
            $this->drive->createDocument($payload['title'], $payload['parentId'] ?? null)->toArray()
        );
    }
}
```

Map the bundle exceptions to HTTP codes that fit your API:

| Exception | Suggested status |
|---|---|
| `AccessDeniedException` | 403 |
| `InheritedPermissionException` | 400 |
| `NotCopyableException` | 400 |
| `UploadTooLargeException` | 413 |
| `InsufficientDriveRoleException` | 500 — it is a setup problem, log it for an administrator |
| `NotConfiguredException` | 503 |
| `Google\Service\Exception` | pass the Google status through |

## Capabilities

Every listed item carries a `DriveCapabilities` telling you what Google will allow, so you can
render the actions that work instead of showing all of them and letting half fail:

```twig
{% if item.capabilities.canRename %}<button>Rename</button>{% endif %}
{% if item.capabilities.canTrash %}<button>Move to trash</button>{% endif %}
{% if item.capabilities.canShare %}<button>Share</button>{% endif %}
```

The flags map one to one onto the service methods: `canEdit`/`canRename` → `rename()`,
`canTrash` → `trash()`, `canUntrash` → `restore()`, `canDelete` → `deleteForever()`,
`canShare` → `grant()`/`revoke()`, `canCopy` → `copy()`, `canDownload` → `export()`,
`canAddChildren` → creating inside a folder, `canMove` → `move()`.

Two things to keep straight:

- **Capabilities describe the service user, not the person browsing.** The bundle talks to
  Google as one account, so a flag answers "will this call succeed", never "is this viewer
  allowed to". Per-viewer visibility stays with `ViewerContextInterface` and `canAccess()` —
  combine both: hide what the viewer may not see, then disable what Google will not permit.
- **A missing flag counts as "not allowed".** `capabilities` itself is null when the metadata
  was not requested, and every individual flag defaults to false.

Worth noticing in practice: with the service user added as "Content manager", items report
`canTrash: true` but `canDelete: false` — which is exactly why `trash()` and `deleteForever()`
are separate methods.

Other metadata now on every item: `createdTime`, `size` (null for Google documents — they
occupy no drive storage), `iconLink` (a stable Google-hosted type icon, safe to embed),
`thumbnailLink` (a preview image; the URL is short-lived and authenticated, so proxy it through
your app rather than putting it straight into a long-cached page) and `lastModifiedBy`.

Only the ten capability flags the bundle exposes are requested, not the thirty-odd Drive
offers, so listings do not balloon.

## Pagination

`listFolder()`, `search()` and `listTrash()` return everything, walking Google's pages for you.
That is fine for a few hundred items; past that, one web request fans out into a request per
page. The `...Page()` variants cost exactly **one** `files.list` call each:

```php
$page = $this->drive->listFolderPage($folderId, $request->query->get('page'));

foreach ($page as $item) { ... }          // DrivePage is iterable and countable

if ($page->hasMore()) {
    // next link: ?page={{ page.nextPageToken }}
}

return $this->json($page->toArray());     // {items: [...], nextPageToken: ..., hasMore: bool}
```

Page size defaults to `DriveDocumentService::DEFAULT_PAGE_SIZE` (100) and is clamped to
Google's maximum of 1000.

One thing to get right in your loop:

- **Drive the loop off `hasMore()`, never off the item count.** Visibility filtering drops
  items after Google has already counted them into the page, so a page can come back short —
  or completely empty — while later pages still hold documents. An empty page is not the end
  of the list; a missing `nextPageToken` is.

The fetch-everything methods now ask Google for pages of 1000 rather than 100, so they also got
ten times cheaper in round trips.

## Importing

`import()` takes the path of a file on disk — `$uploadedFile->getPathname()` for an HTTP upload,
or any path from a command — and lets Google convert it:

```php
// In a controller
$sheet = $this->drive->import($request->files->get('file')->getPathname(), null, $folderId);
$sheet->webViewLink;  // already editable in the embedded editor
```

| Uploaded | Becomes |
|---|---|
| `.xlsx`, `.ods`, `.csv`, `.tsv` | Google Sheet |
| `.docx`, `.odt`, `.rtf`, `.txt`, `.html` | Google Doc |
| `.pptx`, `.odp` | Google Slides |
| `.pdf`, `.zip`, anything else | stored unchanged |

- **The type comes from the file extension**, not from sniffing the bytes — `mime_content_type()`
  reports plain `application/zip` for an `.xlsx`. Pass `$mimeType` explicitly to override it.
- **Converted documents lose the extension in their name.** An uploaded `prices.xlsx` becomes a
  Google Sheet called `prices`; a stored-as-is `scan.pdf` keeps its full name. Pass `$title` to
  decide yourself, and read `originalFilename` off `DocumentImportedEvent` for the audit trail.
- **`convert: false` stores the uploaded file untouched**, which is what you want for a PDF
  attachment rather than an editable document.
- **A single upload is capped at 5 MB.** That is the limit of Google's multipart upload, the
  one-request form this bundle uses; beyond it Drive requires its resumable protocol, which is
  not implemented yet. Larger files raise `UploadTooLargeException` instead of failing obscurely.
- **A stored-as-is file stays invisible to `listFolder()`** unless its MIME type is listed in
  `document_mime_types` — add `application/pdf` there if you import PDFs and want them shown.

## Exporting

`export()` returns a `DriveExport` whose `stream` is the live response body, so a download
never passes through your PHP memory:

```php
use Borsche\GoogleDriveDocsBundle\Model\DriveExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Route('/documents/{id}/download', name: 'document_download')]
public function download(string $id): StreamedResponse
{
    $export = $this->drive->export($id, DriveExport::XLSX);

    return new StreamedResponse(
        static function () use ($export): void {
            while (!$export->stream->eof()) {
                echo $export->stream->read(8192);
            }
        },
        200,
        [
            'Content-Type'        => $export->mimeType,
            'Content-Disposition' => $export->contentDisposition(),
        ]
    );
}
```

Formats are constants on `DriveExport`: spreadsheets `XLSX`, `ODS`, `CSV`, `TSV`; documents
`DOCX`, `ODT`, `RTF`, `TXT`, `EPUB`; presentations `PPTX`, `ODP`; and `PDF`, `HTML`, `ZIP` for
anything. `DriveExport::extensionFor()` maps a format to its file extension.

Two limits worth planning around:

- **Google caps one export at 10 MB.** A heavy spreadsheet fails as PDF or XLSX but usually
  passes as CSV. The limit is Google's, not the bundle's, and it surfaces as a Google error.
- **A file that is not a Google document is not converted.** Its stored bytes come back as they
  are and the requested format is ignored — read `$export->mimeType` instead of assuming.

Exporting is a read operation: it dispatches no event.

## How copying behaves (worth knowing)

- **Folders cannot be copied.** The Drive API has no folder copy — the same reason Google's own
  UI greys out "Make a copy" for folders. `copy()` turns Google's refusal into
  `NotCopyableException`. To duplicate a tree, recreate the folders with `createFolder()` and
  copy each file into them.
- **Without a target folder the copy lands next to the original**, matching Google's default.
  Pass a folder id to place it elsewhere; the Shared Drive root is addressable by the drive id.
- **The copy does not carry the original's sharing.** On a Shared Drive a new file inherits the
  permissions of the folder it lands in, so copying a narrowly-shared document into a widely
  shared folder widens who can see it. Choose the destination deliberately.
- **Templates are ordinary documents.** Keep them in a folder of their own — there is no special
  template type — and `createFromTemplate()` is `copy()` with the new name made mandatory.

## How the trash behaves (worth knowing)

- **`trash()` is the safe default.** The item leaves every regular listing (`listFolder()` and
  `search()` always ask Google for non-trashed items only) but stays recoverable.
- **Google purges the trash of a Shared Drive after 30 days.** After that the item is gone
  regardless of what your application does.
- **Trashing a folder flags only the folder itself.** Its contents keep their own state, so the
  trash lists the folder alone — exactly as Google's own UI does. Consequence worth knowing:
  `listFolder($trashedFolderId)` still lists what is inside, because nothing checks whether an
  ancestor is in the trash. Guard it where you already have the data — any page rendering
  breadcrumbs fetches the folder anyway:

  ```php
  $folder = $this->drive->get($folderId);

  if ($folder->trashed) {
      throw $this->createNotFoundException();
  }
  ```

  The bundle deliberately does not do this inside `listFolder()`: it would cost an extra
  Google round trip on the hottest path to close a case you only reach through a stale link,
  and the viewer would still only see items they already have access to.
- **Restoring returns an item to its original parent.** If that folder is itself in the trash,
  restore it too, otherwise the item stays out of reach.
- **`deleteForever()` needs the Manager role.** A "Content manager" may trash but not purge;
  Google answers 403 and the bundle raises `InsufficientDriveRoleException` explaining the fix.
- **Both operations require access to the item**, exactly like `rename()` and `move()`.

## How sharing behaves (worth knowing)

- **Inheritance is recursive.** Access granted on a folder applies to every sub-folder and file inside it, at any depth.
- **Access can be widened, not narrowed.** You can grant extra access deeper in the tree, but you cannot hide a sub-folder from someone who has access to its parent. Keep such material in a separate top-level folder.
- **Inherited permissions cannot be revoked on the child.** Google rejects it; the bundle turns that into `InheritedPermissionException` and flags those entries with `inherited: true` so your UI can hide the button.
- **Editing happens under the viewer's own Google session.** Anyone who should edit a document needs a Google account that has been granted access to it.
- **Visibility filtering is application-level.** If your users are members of the Shared Drive itself, Google grants them access to everything regardless of what your UI shows. For real isolation, do not add users as drive members — share individual folders instead.

## Retries

Google throttles per user and per project, and a busy listing page can trip that. Out of the
box every call is retried up to three times with exponential backoff and jitter, so a single
rate-limit answer no longer reaches your user as an error.

What is retried: HTTP **429**, **500**, **502**, **503**, **504**, the Drive reasons
`rateLimitExceeded`, `userRateLimitExceeded`, `backendError` and `internalError` (Drive reports
quota problems behind a 403 with one of those), and connection-level curl failures — DNS,
connect, timeout, TLS handshake, empty reply.

What is **not** retried, on purpose: 400, 401, 403 without a rate-limit reason, and 404.
Repeating those cannot change the answer and only burns quota.

Set `retry.attempts: 0` to switch retrying off — useful in tests, where you want a failure to
surface immediately rather than after three backoff waits.

One thing the retry policy does not cover: refreshing the OAuth token happens outside Google's
task runner, so a transient failure there still surfaces directly.

The policy applies to every call, writes included. The Drive API offers no idempotency key, so
in the rare case where Google completes a `create`, `copy` or `grant` and *then* answers with a
5xx, the retry performs it a second time — a duplicated document or sharing entry. This is the
same trade-off Google's own client libraries make; if a duplicate is unacceptable in your
flow, set `retry.attempts: 0` for that call path and handle the failure yourself.

## Performance

When visibility filtering is active the bundle asks Google for the sharing of every listed
item, because Shared Drives usually omit the `permissions` field from `files.list`. Point
`permission_cache.pool` at a PSR-6 pool to keep those lookups off the hot path:

```yaml
google_drive_docs:
    permission_cache:
        pool: 'cache.app'
        ttl: 300
```

Grants and revocations made through the bundle clear the affected entry immediately, so the
UI never shows stale access. Changes made **directly in Google** are picked up only after the
TTL expires — keep it short if people also share from the Drive interface.

Caching is entirely optional: with no pool configured the bundle simply queries Google every
time. If the configured pool does not exist, the application still boots — you get a warning
in the compiler log instead of a silent slowdown.

## Contributing

```bash
composer install
composer test      # PHPUnit
composer phpstan   # static analysis
```

## License

MIT — see [LICENSE](LICENSE).
