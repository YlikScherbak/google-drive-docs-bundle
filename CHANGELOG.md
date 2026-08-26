# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-08-26

### Fixed
- The test suite ran on PHP 8.1 again. `DriveDocumentResolverTest` collected the resolver's
  answer with `iterator_to_array()`, which only accepts a plain array from PHP 8.2 onwards, so
  six tests errored on the oldest PHP this bundle supports. The bundle itself was unaffected —
  only its suite — and the CI job added in 0.7.1 to run against the lowest dependencies is what
  caught it

## [1.0.0] - 2026-08-26

The public API is settled. From here on, minor releases add and only a major one removes — see
[UPGRADE.md](UPGRADE.md), which now covers every step from 0.1.0 and states the promise in full.

### Removed
- `DriveDocumentService::delete()`, deprecated since 0.3.0. It erased an item for good, which its
  name never said: use `deleteForever()` for that behaviour, or `trash()` for what a delete button
  in a UI probably means
- `DriveDocumentService::MAX_UPLOAD_BYTES`, deprecated since 0.7.0 when resumable uploads removed
  the ceiling it described. `MULTIPART_LIMIT` is where the two upload paths part; `upload.max_bytes`
  is for a limit of your own
- `symfony/deprecation-contracts` is no longer required — it existed for that one deprecation

### Changed
- Symfony `^6.0 || ^7.0` narrowed to `^6.4 || ^7.0`. CI only ever verified 6.4 and 7, and for a
  release carrying a compatibility promise, claiming support nothing checks is worse than
  narrowing it. No code changed

### Added
- `DriveVoter` decides access through `is_granted()`: `DRIVE_VIEW`, `DRIVE_EDIT`, `DRIVE_SHARE`,
  `DRIVE_DELETE`, taking a `DriveDocument` or a file id. Registers itself when
  `symfony/security-core` is installed. This is where "the bundle reports, it does not enforce"
  becomes a decision that can be read, overridden and tested
- `DriveDocumentResolver` turns a route parameter into a `DriveDocument` controller argument,
  applying the access check before the controller body runs
- `forDrive($driveId)` returns the same service pointed at another Shared Drive, carrying every
  other setting over. One drive stays the configuration's business
- `UPGRADE.md`

### Notes
- **No `enforce_roles` option, deliberately, and this is the release that settles it.** Which role
  an operation should require is arguable — Google wants `organizer` for some sharing changes and
  `writer` for others depending on the drive's setup — so a matrix inside the service would be a
  wrong answer nobody reviews. `DriveVoter` is that matrix somewhere replaceable
- **`listFolder()` on a folder that is in the trash still lists its contents**, and 1.0 settles
  that too. Google flags the folder alone, so checking would put another Drive call on the hottest
  listing path to cover a case only a stale link reaches, where the viewer sees nothing new. Now
  stated in the method's own docblock, not only in the README
- A profiler panel was on the plan and dropped: Google's client exposes resources as public
  properties rather than methods, so counting calls would mean decorating every resource class.
  A half-useful panel is worse than none

## [0.9.0] - 2026-08-26

### Added
- Access that expires by itself: an `expiresAt` argument on `grant()`, `grantToGroup()` and
  `grantAsService()`, plus `setExpiry()` to give an existing grant an expiry or lift the one it
  has. `DrivePermission` gained `expiresAt` and `expires()`. Google's own restrictions — in the
  future, no more than a year ahead — are checked before the call, because a Drive 400 names
  neither the value nor the grant
- `lock()` and `unlock()` freeze a finished document against editing, with the reason Google
  shows whoever tries. `DriveDocument` gained `locked` and `lockReason`, and a locked item's
  `capabilities` report `canEdit: false`, so a UI built on capabilities needs no changes.
  Reported by the new `DocumentLockChangedEvent`
- `startPageToken()` and `changesSince()` poll for work done **directly in Google**, returning a
  `DriveChanges` of `DriveChange` entries and the token to resume from. Every change seen drops
  that item's cached sharing immediately, which closes a gap open since 0.2.0: a share added by
  hand in the Drive UI no longer waits for the cache to expire
- `google-drive-docs:check` verifies the setup — credentials and who they authenticate as, the
  Shared Drive, what the service user may do on it, and whether the root lists. Four independent
  checks so a half-working setup says which half; read-only throughout. It calls out the common
  case of the service user being a "Content manager", which may trash but not erase

### Fixed
- `shared_drive_id` is held to Google's id alphabet before it is interpolated into a Drive query,
  the way item ids already were. A deliberate debt from 0.3.1

### Notes
- The change token is the application's to store: the bundle has nowhere to keep it. Polling with
  a used token replays those changes, and with one never received loses the gap between
- Push notifications (`changes.watch`) are deliberately not wrapped — they need a public HTTPS
  endpoint with a valid certificate, which belongs to the application rather than to a library
- Comments were on the plan for this release and were dropped: the most speculative item in the
  set, and the same size as half the change polling that closes a real gap

## [0.8.0] - 2026-08-26

### Added
- Version history: `listRevisions()`, `revision()`, `keepRevision()`, `deleteRevision()` and
  `exportRevision()`, with a `DriveRevision` model carrying when a version was saved, who saved
  it, its size, whether it is pinned and which formats it can be fetched as. The trash protects
  against losing a file; this is what protects against a spreadsheet someone overwrote
- `RevisionKeptEvent` and `RevisionDeletedEvent`

### Notes
- **There is no rollback, and none is pretended.** Drive API v3 lists, reads, pins and deletes
  revisions; only the Google editor can make an old version current again. `exportRevision()`
  fetches the old content so the application can decide where it goes — `import()` it beside the
  live document, or read the values back with `SpreadsheetService`. The README carries both
  recipes
- **The revision list can be incomplete.** Google documents that older revisions are omitted for
  files with a long history, frequently edited Sheets and Docs especially, and that the Workspace
  editor may show more than the API. Said plainly in the README and on the model, because it
  would otherwise look like an audit trail
- A Google format stores no bytes of its own, so its revisions carry export links and the MIME
  type chooses between them; an uploaded file's revision is downloaded directly. `exportRevision()`
  settles which of the two applies, and refuses a format the revision does not offer, before
  spending a request on the document's name

## [0.7.1] - 2026-08-26

### Fixed
- Resumable upload declared the wrong total when a file's stat could not be trusted. A stat
  answering `false` — or `0`, as a network mount or a stream wrapper can — sent the upload down
  the multipart path, where the bounded read then handed the resumable fallback the size of what
  it had read (5 MB) rather than the file's real size. Google measures every chunk against that
  total and rejected the first one past it. The size now comes from the stream itself, and a
  stream that can neither stat nor seek is reported as such instead of uploading nonsense
- `upload.max_bytes` was skipped entirely on that same path: with no trustworthy stat the cap
  was never applied. Both upload paths now check the bytes they actually handle, so the ceiling
  holds whatever the filesystem claims
- A resumable upload whose last chunk was the single byte `0` stalled one byte from done: the
  Google client tests its chunk argument with a loose comparison, and PHP reads the string `"0"`
  as false. The loop no longer leaves a single byte for the final chunk — it swallows that byte
  into the chunk before it, which is what makes that one the last. Shortening a chunk instead
  would have sent an intermediate one that is not a multiple of 256 KB, breaking the rule
  `chunk_bytes` itself is validated against; every chunk but the last is now asserted to be a
  multiple of the granularity
- `chunk_bytes` is validated where it is configured: a value that is not a multiple of 256 KB
  used to pass config validation and fail only when the service was first used, far from the
  cause. It is also capped at the new `MAX_CHUNK_BYTES` (64 MB) — a chunk is read into memory
  whole, so a gigabyte-sized one is a mistake rather than a tuning choice
- `roleOf()` no longer stops at the permissions Google embeds in a file, which are not always
  the whole list: it reads the dedicated lookup as well, so a `writer` grant held through a
  group is not reported as the weaker `reader` found inline
- `SpreadsheetService` and `SheetFormatter` tolerate a spreadsheet answer without `sheets`, as
  `listTabs()` already did; a formatting pass against one now says the answer described no
  usable tab instead of "no tab called ''"
- `SheetRange` refuses a range past the edges of the grid, naming it instead of leaving a Drive
  400 to explain. The bounds are Google's own and deliberately loose: 18 278 columns (A to ZZZ)
  and the ten million cells a spreadsheet can hold, which is also the most rows any range could
  name. 16 384 columns and 1 048 576 rows are Excel's grid, and using those would have refused
  ranges Google accepts

### Added
- `SheetFormatter::MAX_OPERATIONS` (500): a pass travels as one `batchUpdate`, so the call that
  overflows it raises an `OverflowException` naming itself. This ceiling is the bundle's own, not
  a documented Google limit — the same reasoning as `MAX_PAGES` and `MAX_BATCH_RANGES`: a request
  that grows without bound is a runaway rather than a styling pass
- `DriveDocumentService::MAX_CHUNK_BYTES`

### Changed
- The pixel range `columnWidth()` and `rowHeight()` accept is described as this bundle's own
  policy, which it is, rather than as Google's limit
- README: `max_bytes: 0` means no limit of your own, the `chunk_bytes` rules, the size of a
  formatting pass, and that `import()` trusts the path it is given

## [0.7.0] - 2026-08-25

### Added
- `setAppProperties()`, `appProperties()`, `findByAppProperty()` and `findByAppPropertyPage()`
  store the application's own metadata on an item and search by it, so "the spreadsheet
  belonging to order 4711" is a question Drive can answer instead of a mapping table you keep.
  Drive holds `appProperties` privately per OAuth client, so nothing else looking at the drive
  sees them. Values are stored as strings, a null value removes a key, and every call is a merge
- `DocumentPropertiesChangedEvent`
- Resumable upload: `import()` no longer refuses a file over Google's 5 MB multipart ceiling.
  Under it the file still goes up in one request; past it the bundle switches to Drive's
  resumable protocol and sends the bytes in chunks, so nothing larger than one chunk is held in
  memory and Drive's own 5 TB is the only ceiling left
- `upload.max_bytes` for a policy limit of your own (0, the default, means none) and
  `upload.chunk_bytes` for the resumable chunk size, which must be a multiple of 256 KB
- `DriveDocumentService::MULTIPART_LIMIT` and `CHUNK_GRANULARITY`

### Changed
- **A file over 5 MB is uploaded instead of rejected.** `UploadTooLargeException` now means only
  that the application's own `upload.max_bytes` was exceeded — code catching it to tell users
  "too big for the integration" should either set that option or stop expecting the exception
- `search()` and the new property search share one query-escaping helper, so both treat a quote
  or a backslash in the term the same way

### Deprecated
- `DriveDocumentService::MAX_UPLOAD_BYTES`, which no longer describes the largest upload. Use
  `MULTIPART_LIMIT` for the point where the two upload paths part, or the `upload.max_bytes`
  option for a limit of your own

### Notes
- The resumable path has to put the Google client into deferred mode, which is global to the
  client: leaving it on would turn every later call anywhere in the application into a request
  object instead of a result. It is restored in a `finally`, and there is a test that a failure
  mid-upload still restores it
- What is tested here is the part this bundle owns: which path a size takes, that the client
  state is put back, the chunk-size rule and the application cap. The byte-level chunk protocol
  is Google's own `MediaFileUpload` and is exercised for real rather than mocked in detail

## [0.6.0] - 2026-08-25

### Added
- `SpreadsheetService::format()` starts a formatting pass and sends the whole thing as one
  `spreadsheets.batchUpdate`: `style()` (bold, italic, font size, colour, background,
  horizontal alignment, wrapping), `numberFormat()`, `freeze()`, `autoResizeColumns()`,
  `columnWidth()`, `merge()` and `unmerge()`. Data filled into a template no longer has to
  look raw
- `SpreadsheetService::addTab()` adds a tab and returns the numeric sheet id Google assigned
- `SheetRange` parses A1 notation into the zero-based, half-open indices Google's formatting
  calls want, so callers keep writing `'Q3!A1:D10'` and never meet a `GridRange`. Handles
  quoted tab names, doubled apostrophes, open-ended ranges (`D2:D`, `A:D10`), whole columns
  and rows, and normalises a reversed range
- The rest of the formatting surface on the same pass: `borders()`, `bandedRows()`,
  `conditionalFormat()`, `dataValidation()`, `basicFilter()`, `clearBasicFilter()`,
  `protect()`, `rowHeight()`, `hideTab()`, `showTab()` and `tabColor()`. Conditions use
  Google's own vocabulary (`NUMBER_LESS`, `ONE_OF_LIST`, `CUSTOM_FORMULA`, …) and are passed
  through rather than mirrored in a list the bundle would have to keep current
- `SpreadsheetService::renameTab()` and `deleteTab()`. Renaming keeps the numeric sheet id, so
  formulas and charts pointing at the tab keep working, and refuses a title another tab holds.
  Deleting refuses the last remaining tab, and has no undo beyond the spreadsheet's own version
  history — treat it the way you treat `deleteForever()`
- `SheetFormattedEvent`, `SheetTabAddedEvent`, `SheetTabRenamedEvent` and `SheetTabDeletedEvent`

### Notes
- `style()` writes only the attributes it is given. Google's `repeatCell` clears everything its
  field mask covers, so each call builds a mask naming exactly what was asked for — passing
  `bold: true` leaves the background, alignment and number format on those cells alone
- `freeze()` and `autoResizeColumns()` take a tab name rather than a range, because a bare `Q3`
  in A1 notation is the cell Q3 and a range would be ambiguous exactly where it matters
- No `unprotect()`: removing a protection means finding the id Google assigned it, which is
  work for whoever is looking at the spreadsheet rather than for something generating one
- Not covered: charts and pivot tables, named ranges, developer metadata, and sorting a range
  in place

## [0.5.0] - 2026-08-25

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

[Unreleased]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/releases/tag/v0.1.0
