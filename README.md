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
- **Create / rename / move / delete** documents and folders
- **Embed the native Google editor** via the `webViewLink` of each document
- **Manage sharing**: list, grant and revoke access per file or folder (`reader` / `commenter` / `writer`), for individual users or **Google groups**
- **Per-user visibility**: users only see the items shared with them; administrators see everything
- **Inheritance-aware**: sharing a folder cascades to its whole subtree, and inherited permissions are flagged so your UI can hide a "remove" button that Google would reject
- **OAuth-based auth** — works on organisations where service-account keys are disabled by policy
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

// Documents and folders
$doc    = $this->drive->createDocument('Q3 report', $folderId);
$folder = $this->drive->createFolder('Portugal');
$this->drive->rename($doc->id, 'Q3 report (final)');
$this->drive->move($doc->id, $otherFolderId);         // null → move to root
$this->drive->delete($doc->id);

// Sharing
$permissions = $this->drive->listPermissions($doc->id);
$this->drive->grant($folder->id, 'user@example.com', 'writer');
$this->drive->grantToGroup($folder->id, 'portugal@example.com', 'writer'); // whole team at once
$this->drive->revoke($doc->id, $permissionId);

// Embed in your UI
echo $doc->webViewLink; // https://docs.google.com/spreadsheets/d/<id>/edit
```

Every item is returned as a `DriveDocument` (`id`, `name`, `mimeType`, `webViewLink`, `modifiedTime`, `type`) and every sharing entry as a `DrivePermission` (`id`, `emailAddress`, `role`, `type`, `displayName`, `inherited`, `inheritedFrom`). Both expose `toArray()` for JSON responses.

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
| `DocumentRenamedEvent` | an item is renamed | `document` |
| `DocumentMovedEvent` | an item is moved | `document`, `fromParentId`, `toParentId` |
| `DocumentDeletedEvent` | an item is trashed | `fileId` |
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
| `NotConfiguredException` | 503 |
| `Google\Service\Exception` | pass the Google status through |

## How sharing behaves (worth knowing)

- **Inheritance is recursive.** Access granted on a folder applies to every sub-folder and file inside it, at any depth.
- **Access can be widened, not narrowed.** You can grant extra access deeper in the tree, but you cannot hide a sub-folder from someone who has access to its parent. Keep such material in a separate top-level folder.
- **Inherited permissions cannot be revoked on the child.** Google rejects it; the bundle turns that into `InheritedPermissionException` and flags those entries with `inherited: true` so your UI can hide the button.
- **Editing happens under the viewer's own Google session.** Anyone who should edit a document needs a Google account that has been granted access to it.
- **Visibility filtering is application-level.** If your users are members of the Shared Drive itself, Google grants them access to everything regardless of what your UI shows. For real isolation, do not add users as drive members — share individual folders instead.

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
