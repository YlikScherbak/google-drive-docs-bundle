# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `roleOf()` reports the role the current viewer effectively holds on an item — the strongest of
  every grant that applies, whether it names them directly or reaches them through a group, on
  the item itself or on a folder above it. Roles the bundle cannot grant (`owner`, `organizer`,
  `fileOrganizer`) are reported as they are; a viewer whose `seesEverything()` is true gets
  `null`, since nothing is looked up for them. Until now the access check was about reach only,
  and an application had no way to tell a reader from an editor short of reading
  `listPermissions()` itself
- The sharing lookup now asks Google for `role` alongside the address, so the answer costs no
  extra request

### Changed
- `roleOf()` reports, it does not enforce: `canAccess()` still passes anyone holding any grant,
  and the bundle still performs the operation as the service user. Which role a given operation
  should require stays the application's decision — Google's own answer varies with how the
  Shared Drive is configured, and a wrong one baked into a library locks people out
- The permission cache stores a role per address rather than a bare list of addresses, so its
  key carries a version. Entries written by 0.4.0 and earlier are not read back — expect one
  cold lookup per item after upgrading, nothing more

## [0.4.0] - 2026-08-25

### Added
- `SpreadsheetService` reads and writes the cells of a Google Sheet, so a template can be
  filled with the application's own data: `listTabs()`, `read()`, `readMany()`, `write()`,
  `writeMany()`, `append()`, `clear()` and a `range()` helper that quotes tab names correctly.
  It runs on the same authenticated client as the Drive side, so the retry policy applies, and
  every call — reads included — asks `DriveDocumentService` for access, so a spreadsheet's
  contents are exactly as reachable as the spreadsheet itself. Registered as
  `google_drive_docs.spreadsheets`. Until now the bundle asked for the `auth/spreadsheets`
  scope and told you to enable the Sheets API without ever calling it
- `SheetValuesUpdatedEvent`, `SheetRowsAppendedEvent` and `SheetRangeClearedEvent`
- `SpreadsheetService::MAX_BATCH_RANGES` (100): `readMany()` and `writeMany()` refuse a longer
  list of ranges with an `InvalidArgumentException` before calling Google. The cap is the
  bundle's own, not a documented Google limit — `batchGet` sends ranges in the query string,
  where a long list becomes a URL that may be rejected unhelpfully

### Fixed (before release, part of the feature above)
- `SpreadsheetService::range()` quotes tab names that read like a cell reference (`Q3`, `A1`,
  `ZZ999`): unquoted, Google treats them as that cell of the first tab, so `append()` to a tab
  called `Q3` would have written to the wrong place
- `read()` / `readMany()` keep the types Google sends under `RENDER_RAW` — `int`, `float`,
  `bool` — instead of casting everything to string, which turned an unticked checkbox into
  the same `''` as an empty cell and lost float precision
- `writeMany()` reports Google's `updatedRows` per block in `SheetValuesUpdatedEvent`, as
  `write()` already did, rather than the number of rows handed in
- `SheetRowsAppendedEvent::$range` carries where the rows actually landed as reported by
  Google (`'Q3'!A10:B12`), falling back to the requested range
- `listTabs()` tolerates a spreadsheet answer without `sheets`

### Changed
- README: visibility is about reach, not role — a `reader` passes the access check and the
  bundle then acts as the service user; how to tell readers from editors. Ranges are developer
  input, whole-tab reads and the 100-range batch ceiling. `append()` listed among the
  non-idempotent calls the retry policy may repeat

## [0.3.1] - 2026-08-25

### Security
- `grant()`, `grantToGroup()`, `revoke()` and `listPermissions()` now check the viewer's
  access to the item first and throw `AccessDeniedException` otherwise, like every other
  operation on a single item already did. Before, a viewer who knew a file id could share
  any document with themselves, revoke other people's access or read who a document is
  shared with. Applications whose `ViewerContextInterface::seesEverything()` returns true
  (the default `AllowAllViewerContext` included) are unaffected

### Fixed
- `permission_cache.ttl: 0` now means "do not use the shared pool" (per-request caching
  only). Before, entries were saved without a lifetime and lived in the pool until it was
  cleared — the opposite of what a zero lifetime suggests
- `import()` verifies the size of the bytes it actually read, not only the size reported by
  `filesize()`: a failed stat or a file growing while being read no longer slips an
  oversized upload past `UploadTooLargeException`
- `canAccess()` no longer turns a Google outage into a denial: only a 403/404 from Google
  means "not shared", any other error surfaces. Likewise the sharing lookup used by listings
  keeps swallowing transient API exceptions for the current request only, but lets programming
  errors (`TypeError` and friends) propagate instead of silently hiding every document
- `canAccess()` reports a missing `shared_drive_id` with `NotConfiguredException` right away
  instead of walking up to 25 parents in vain
- `move()` refuses with a `RuntimeException` when Google does not report the item's current
  parent, rather than sending an empty `removeParents`
- Folder ids passed to `listFolder()` / `listFolderPage()` are validated against Google's id
  alphabet before they are interpolated into the Drive query
- `deleteForever()` no longer mistakes a 403 carrying `rateLimitExceeded` /
  `userRateLimitExceeded` (an exhausted quota after the retries) for a missing Manager role
- `DocumentDeletedEvent` docblock described the trash; it is dispatched on permanent deletion
- Matching Google's machine-readable `reason` no longer breaks on an error that carries none.
  `Google\Service\Exception::getErrors()` returns null, not an empty array, whenever the
  response body has no `error.errors` — a proxy 502, an empty 429 — and iterating that raised a
  warning that a strict error handler (Symfony's, in dev) turns into a throw, masking the real
  Google failure in `copy()`, `revoke()` and `deleteForever()`
- `import()` reads at most one byte past the upload limit instead of the whole file, so a
  failed `filesize()` can no longer pull an arbitrarily large file into memory before the size
  check rejects it
- `copy()` and `revoke()` recognise Google's `fileNotCopyable` / `cannotCopyFile` and
  `cannotDeletePermission` / `cannotModifyInheritedPermission` by the machine-readable `reason`
  instead of the wording of the message, which Google may change at any time
- Every paginated walk (`listFolder()`, `search()`, `listTrash()`, `listPermissions()` and the
  sharing lookup) stops with a `RuntimeException` after 1000 pages instead of looping — and
  eventually running out of memory — on a `nextPageToken` that never ends

### Added
- `grantAsService()` shares on behalf of the application, skipping the viewer's access check
  that `grant()` performs. Without it the access check above has no answer for the
  "creator gets access" pattern: a document the service user just created in the drive root is
  shared with nobody, so the grant meant to give its creator access is itself a grant on an
  item that creator cannot yet reach. Role and grantee type are still validated, the event is
  still dispatched and the cached sharing is still invalidated. A separate method rather than a
  flag on `grant()`, so every bypass is one grep away (#1)
- `UnexpectedDriveStateException` for the two cases where Google answers something that cannot
  be true — an item on a Shared Drive with no parent, and a listing whose `nextPageToken` never
  ends. Both previously raised a bare `RuntimeException`, which slipped through the typed
  catches consumers map onto status codes and surfaced as an untyped 500 (#2)
- A compiler pass that leaves a note in the container's compiler log while
  `ViewerContextInterface` is still the default `AllowAllViewerContext`, so running without
  visibility filtering is always a choice, never an oversight
- CI job running the test suite against the lowest dependency versions `composer.json` allows

### Changed
- README documents that retries also cover non-idempotent writes and what that implies

## [0.3.0] - 2026-08-25

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

[Unreleased]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/releases/tag/v0.1.0
