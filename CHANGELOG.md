# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Capabilities: every item now carries a `DriveCapabilities` (`canEdit`, `canRename`,
  `canDelete`, `canTrash`, `canUntrash`, `canShare`, `canCopy`, `canDownload`,
  `canAddChildren`, `canMove`) reporting what Google will allow, so a UI can render only the
  actions that work. Note the flags describe the service user the bundle authenticates as,
  not the person browsing — per-viewer visibility stays with `ViewerContextInterface`
- Richer metadata on `DriveDocument`: `createdTime`, `size` (null for Google documents),
  `iconLink`, `thumbnailLink` and `lastModifiedBy`. All are optional constructor arguments
  defaulting to null, and all appear in `toArray()`
- Retries: every Google call now backs off exponentially and tries again on HTTP 429/500/502/
  503/504, on the Drive reasons `rateLimitExceeded`, `userRateLimitExceeded`, `backendError`
  and `internalError`, and on connection-level curl failures. Client mistakes (400/401/403/404)
  are deliberately never retried. Tunable through the new `retry.attempts`,
  `retry.initial_delay` and `retry.max_delay` options; `attempts: 0` restores the old
  fail-immediately behaviour
- Pagination: `listFolderPage()`, `searchPage()` and `listTrashPage()` return a `DrivePage`
  (iterable, countable, `toArray()`-able) holding one Google page plus its `nextPageToken`,
  so a listing costs a single `files.list` call instead of one per hundred items
- Import: `import()` uploads a local file and has Google convert it into the matching editable
  document — spreadsheets into Google Sheets, text documents into Google Docs, decks into
  Slides — or stores it unchanged with `convert: false`. Reports the new
  `DocumentImportedEvent`, and refuses anything above Google's 5 MB multipart ceiling with
  `UploadTooLargeException` rather than failing obscurely
- Export: `export()` renders a document into XLSX, CSV, PDF, DOCX and the other formats
  listed on the new `DriveExport` model, which carries the download `filename`, the real
  `mimeType`, a `contentDisposition()` header value and the response body as a PSR-7 stream
  so downloads are never buffered in PHP memory
- Copying: `copy()` duplicates a document (beside the original by default, or into a folder
  under a new name) and `createFromTemplate()` starts a document from a template document
- `DocumentCopiedEvent`, carrying the new `document`, the `sourceId` it came from and the
  target `parentId`
- `NotCopyableException`, raised when Google refuses a copy — in practice a folder, which the
  Drive API cannot copy at all
- Trash support: `trash()`, `restore()`, `listTrash()` and `deleteForever()`. Deleting is no
  longer a one-way door — items stay recoverable until Google purges the trash (30 days on a
  Shared Drive)
- `DocumentTrashedEvent` and `DocumentRestoredEvent`, both carrying the updated `DriveDocument`
- `InsufficientDriveRoleException`, raised when Google refuses a permanent delete because the
  service user is a "Content manager" rather than a "Manager" of the Shared Drive
- `DriveDocument::$trashed`, so `get()` on a trashed item no longer reports it as live.
  It is the seventh constructor argument and defaults to `false`; `toArray()` gained a
  matching `trashed` key

### Changed
- `listFolder()`, `search()` and `listTrash()` keep returning every item and their signatures
  are unchanged, but now fetch pages of 1000 instead of 100 — ten times fewer round trips on
  a large drive. They are built on the new paged methods, so behaviour is otherwise identical
- `DocumentDeletedEvent` now documents what it always did: the item was erased for good, not
  trashed. Its docblock and the README claimed the opposite. Listeners keep their meaning —
  the event is dispatched from `deleteForever()`
- A 403 on a permanent delete is translated into `InsufficientDriveRoleException` instead of
  surfacing as `Google\Service\Exception`. Code catching the latter around `delete()` must
  catch the new exception as well
- Permanent deletion now clears the item's entry from the permission cache, which `delete()`
  previously left behind until the TTL expired

### Deprecated
- `DriveDocumentService::delete()`. Its behaviour is unchanged — it erases the item for good —
  but the name no longer says so. Replace it with `deleteForever()` to keep that behaviour, or
  with `trash()` to move the item to the trash instead. It will be removed in 1.0

## [0.2.0] - 2026-08-24

### Added
- Optional PSR-6 caching of sharing lookups (`permission_cache.pool` / `permission_cache.ttl`),
  invalidated immediately when access is granted or revoked through the bundle
- Compiler pass warning when the configured cache pool does not exist, instead of caching
  silently doing nothing
- Sharing with Google groups: `grantToGroup()` and a `$type` argument on `grant()`
- Visibility filtering understands group grants via `ViewerContextInterface::getViewerGroups()`
- PSR-14 events for every write operation (`DocumentCreatedEvent`, `FolderCreatedEvent`,
  `DocumentRenamedEvent`, `DocumentMovedEvent`, `DocumentDeletedEvent`, `AccessGrantedEvent`,
  `AccessRevokedEvent`) so auditing and notifications can live outside the bundle
- Test suite (PHPUnit) covering visibility filtering, parent-chain access checks,
  sharing behaviour, folder/document creation and configuration handling
- Continuous integration on GitHub Actions (PHP 8.1–8.3 × Symfony 6.4/7) and PHPStan analysis

### Changed
- `ViewerContextInterface` gained `getViewerGroups(): array`. Implementations must add it;
  return an empty array when sharing with groups is not used.

## [0.1.0] - 2026-08-24

### Added
- Browse folders and documents of a Google Shared Drive, navigate any depth, search by name
- Create, rename, move and delete documents and folders
- Per-item sharing management (`reader` / `commenter` / `writer`)
- Per-user visibility filtering driven by Google sharing, with ancestor-folder inheritance
- Inherited permissions are flagged; revoking them raises a dedicated exception
- OAuth refresh-token authentication and a console command to obtain the token
- `ViewerContextInterface` extension point so the host application decides who sees what

[Unreleased]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/releases/tag/v0.1.0
