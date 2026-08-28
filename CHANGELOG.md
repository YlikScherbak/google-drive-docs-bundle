# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.3] - 2026-08-28

### Fixed
- The sharing lookup did not ask Drive for the `view` of a grant, so the metadata-only check in
  it could never fire — Drive fills in only the fields it is asked for. The downgraded grant on a
  limited-access folder was still refused, because Drive also marks it inherited and that mark was
  read; but the second line of defence the 1.1.2 entry describes was a check on a field that never
  arrived. It is asked for now, and a test asserts the mask on the request that leaves the process
  rather than the argument handed to the SDK — the same lesson as 1.1.0's query tests
- The boundary and the metadata refusal are now also tested against models that declare neither
  field, the way the oldest `google/apiclient-services` this package allows builds them, so the
  reading path the lowest-dependencies CI job exercises is exercised by the ordinary suite too
- The limited-access boundary read its two fields through generated getters that the oldest
  `google/apiclient-services` this package allows does not have, so every test errored there and
  1.1.2 went out with that CI job red. Both fields are read from the model itself now, which works
  on any version: Google returns them regardless, and the model keeps every key it is given.

  Raising the dependency floor was the other option and the wrong one — it would force an upgrade
  for a security fix that does not need one, and leave anyone who could not upgrade with no boundary
  at all. Checked by installing the lowest dependencies and running the suite and the analysis
  against them, where the getter is genuinely absent.

### Documentation
- The boundary is stricter than Drive in one respect: Google lets an `organizer` or `owner` from
  above a limited-access folder through it, and the walk stops for every role. Said where the
  setting is explained, with why the two agree in practice
- Why `PATCH` is not among the methods the connection-failure retry repeats, next to the note on
  `PUT` and `DELETE`

## [1.1.2] - 2026-08-28

### Security

- **A grant above a limited-access folder reached the documents inside it.** Drive can mark a folder
  so that permissions from above no longer apply to its contents — "limited access" in the
  interface, `inheritedPermissionsDisabled` over the API. The walk up the parents did not know about
  it and climbed straight past, so a viewer with `writer` on an outer folder was reported as `writer`
  on a document inside the limited one, and `DriveVoter` granted `DRIVE_EDIT`, `DRIVE_SHARE` and
  `DRIVE_DELETE` on it. The walk stops at that boundary now.

  Measured against a real drive rather than read off a page, and the mechanism is not quite what it
  looks like: Google does **not** report the outer grant on the file inside. What it does is
  downgrade that grant on the limited folder itself to a reader with `view=metadata` — enough to see
  the folder exists. So the way across the boundary was to keep climbing to the outer folder, where
  the grant is direct and undowngraded.

  A metadata-only grant is no longer treated as access either, which is the second half of the same
  fix: reading Drive's downgrade as permission takes a refusal for a grant.

- **An ambiguous transport failure is no longer repeated on a write.** The retry added in 1.1.0
  repeated every `ConnectException`, on the reasoning that a connection which never opened cannot
  have been acted on. That reasoning was wrong: Guzzle maps five curl errors to that exception and
  two of them say nothing about whether Google acted — a timeout (28) and a server that sent nothing
  (52) can both happen after Drive has appended the row. So a timed-out `append()` was sent again
  and the row was written twice.

  The three that really cannot have been sent — could not resolve host, could not connect, TLS
  handshake — are still retried whatever the method is. For the other two, and for a handler that
  reports no error code at all, only the methods with no side effects are repeated. A `POST` that may
  have been applied reaches the caller as a failure, because a duplicated row is worse than an error
  someone can see.

### Fixed

- **A document shared through its folder went missing from every whole-drive listing.** `canAccess()`
  walks up the parents; the listings filtered per item and did not, which was the same answer until
  1.1.0 stopped caching inherited grants under the child. After that a document reachable by its
  folder's grant was absent from `search()`, `findByAppProperty()`, `listTrash()` and their paged
  forms — fail-closed, and still most of how anyone finds anything. Both now go through one walk,
  which also means one memo: the folders a page shares are read once, not once per item.
- `forDrive()` carries the logger over. Without it a clone stopped reporting the sharing lookups it
  hides, which is the one thing nothing else records.

### Documentation

- "Access can be widened, not narrowed" was true before Drive had limited-access folders and is not
  now. What replaces it says what the setting does, what Google's downgrade means, and that the
  bundle honours the flag rather than offering to set it.
- `export()` "never passes through your PHP memory" overstated the paragraph above it, which already
  says the body is buffered into `php://temp` and spills to disk past about 2 MB. It now says the
  download is never materialised in PHP memory all at once, which is what actually happens.

## [1.1.1] - 2026-08-27

### Fixed
- The static-analysis check that 1.1.0 added to the lowest-dependencies CI job failed there, which
  is the check doing its job and the release shipping red anyway. On symfony/config 6.4 every
  `->end()` in a configuration tree is annotated as returning `NodeParentInterface`, so each node
  after the first reads as a call on the wrong type — the runtime is unaffected, and 7.x annotates
  it correctly.

  Ignored for that one file and that one message rather than worked around in the code:
  restructuring a fluent builder to satisfy an old annotation set would make it worse to read and
  change nothing that runs. Verified by installing the lowest dependencies and running both the
  analysis and the suite against them, rather than by reasoning about which annotation applies —
  the first attempt at this was a guess, and it was wrong.

## [1.1.0] - 2026-08-27

An external audit of 1.0.9 read all 6,039 lines, ran its findings as reproduction scripts against
intercepted HTTP, and tried to refute the important ones. This is the whole of it: two security
defects, nine of medium severity, eleven small ones, and the documentation that disagreed with the
code. A minor rather than a patch because it adds configuration and a class, not because anything
was taken away.

### Security

- **A percent-encoded quote broke out of the Drive query.** `escapeQueryValue()` escaped the
  backslash and the quote, and the Google client URL-decodes every query parameter before
  re-encoding it — so `%27` in a search term arrived at Drive as a bare quote and closed the string
  literal. `search("zzz%27 or fullText contains %27password")` became a query with an extra clause.
  The per cent sign is escaped first now, which keeps it literal; `50% off` still searches for
  `50% off`.

  Worse than the search case was the lookup: `findByAppProperty('orderId', $fromTheRequest)` could
  be steered to a different file, so "the document for order 4711" returned whatever the caller
  named instead. Ranges in `readMany()` travel through the same encoder and are escaped too, where
  only correctness was at stake.

  A viewer with filtering enabled could not reach anything they were not already allowed to see —
  `isGrantedTo()` still applied per item — so this widened a query rather than crossing a boundary.
  For `seesEverything()` and for server-side lookups it chose the file.

- **Revoking on a folder left the child's cached answer in place.** On a Shared Drive, the sharing
  list of a file also reports what it inherits from the folders above it, and those were cached
  under the file's own key. Clearing the folder's entry cleared nothing the file would read, so the
  revoked access went on working for up to the cache TTL. The README's promise that revocations
  "clear the affected entry immediately" was false in exactly the case that matters.

  Inherited grants are recognised now and left to the ancestor that grants them, which the walk
  reads on its own — which is what `directGrants()`, and its name, always claimed.

  Membership of the **drive** is deliberately not treated the same way. It arrives as inherited too,
  but from the drive rather than from a folder, and the walk stops at the root without reading it;
  dropping it would have hidden documents from people who can open them in Drive directly. Both
  halves were measured against a real Shared Drive rather than reasoned about, and the second one
  was a regression this release introduced and then caught.

### Fixed

- **Connection failures were never retried**, though the configuration and the README both said they
  were. The curl error codes in the retry map could not fire: the SDK's task runner catches
  `Google\Service\Exception` alone, and a connection that never opened arrives as a Guzzle exception
  carrying no response, which the REST layer rethrows untouched. They are retried now by a
  middleware one layer lower, where they actually appear, and the dead keys are gone. A response is
  still left to the task runner, so the two ladders cannot stack.
- **There was no HTTP timeout, and no way to set one.** Guzzle defaults to waiting for ever, so a
  TCP session Google never answered held a worker until the process was killed. `http.timeout` and
  `http.connect_timeout` are configurable and default to 30 and 10 seconds.
- **A Google outage inside the sharing lookup answered "not shared with you".** For a single item
  that turned an outage into a denial the caller could not tell from a real one; `canAccess()` and
  `roleOf()` raise it now. A listing still hides what it cannot check, because losing one lookup
  must not lose the page.
- **A failing listing could sleep for hours.** Each item's lookup carries its own ladder of retries,
  so a sustained 5xx was slept through once per item — minutes for a page, hours for a large one —
  before returning an empty list anyway. The first failure now stands for the whole request.
- **`setAppProperties($id, ['key' => null])` did not remove the key.** The client drops a plain null
  when it serialises, so the removal never left the process. This is the same defect as `setExpiry()`
  in 1.0.2, in the second place it applied and the place that was missed, and it slipped through for
  the same reason: the test read the getter on the object it had just written to.
- **The per-request sharing memo was never cleared**, which is only per-request under PHP-FPM. Under
  FrankenPHP, RoadRunner, Swoole or a Messenger consumer it lived as long as the worker, outlasting
  any TTL and outlasting it even with no pool configured. The service implements `ResetInterface` and
  is tagged `kernel.reset`.
- **A route parameter named after its argument resolved to a string.** The bundle registered its
  resolver at the same priority as FrameworkBundle's own, and on a tie the framework's usually wins,
  so `#[Route('/d/{document}')]` with a `DriveDocument $document` reached the controller as a
  `TypeError` — the first of the two forms the README documents. The priority is above it now.
- An empty `nextPageToken` ended the loop in two places and not in the other three. Where it did
  not, an empty token read as "there is more" and asked for the same first page until the page
  budget ran out.
- Grants Google embeds in the file itself were counted without checking their expiry, while the
  dedicated lookup had always skipped an expired one.
- `+tag` in an address is folded away only on `gmail.com` and `googlemail.com`, the domains Google
  ignores it on. Folding it everywhere let `alice+anything@corp.com` answer for `alice@corp.com` and
  the other way round.
- The drive id names the root and is reachable as one, as the README says. Asking about it directly
  used to answer false and refuse every call that named it, while the same call with `null` worked.
- An empty string counts as no parent everywhere a parent is taken. `?parentId=` arrives as `''`, and
  the paths disagreed: creating fell back to the root but announced a parent of `''`, copying sent
  Google `parents: ['']`, and listing refused it.
- A resumable upload reads with `stream_get_contents()` rather than `fread()`. A stream wrapper may
  return less than asked for, and a short chunk that is not the last one is not a multiple of the
  256 KiB Google requires.
- `InheritedPermissionException` keeps the original Drive error as its previous exception, as the
  other translated exceptions already did.
- A backslash in a file name no longer escapes the closing quote of the `Content-Disposition`
  filename, which left the header unterminated.
- `SheetRange` says what to do when a tab name parses as a cell reference: quote it.
- The compiler pass for a missing cache pool notes it in the compiler log only. It also raised a
  user warning, which an application with a throwing error handler turned into a failed
  `cache:clear` — a hard failure for something meant to be a note.

### Added

- `http.timeout` and `http.connect_timeout` configuration.
- An optional PSR-3 logger as the service's last constructor argument, used where a sharing failure
  is deliberately hidden. Hiding a document because Google was briefly unavailable looks exactly
  like the document not being shared, and nothing recorded the difference.
- `RetryOnConnectionFailure`, the middleware behind the retry described above.
- PHP 8.4 and 8.5 in the CI matrix, and static analysis on the lowest supported dependencies —
  where it had been finding a real disagreement between an annotation and the runtime that the
  matrix never ran.

### Performance

- The walk up the parents is read once per request instead of once per question. A controller three
  folders deep asked for the same chain three times over — resolver, voter, action — for 13
  `files.get` where 4 were needed. `roleOf()` also stops early once it has the strongest role there
  is. Both are cleared by `reset()` and by any sharing change.

### Documentation

- `export()` returns a PSR-7 stream, and the body has already been received when you get it: the
  SDK's transport is not asked to stream, so Guzzle buffers into `php://temp`. Memory stays flat and
  spills to disk past 2 MB; the first byte simply arrives after the last one. The README said
  "streamed, never buffered" in three places. `exportRevision()` does stream.
- Which of the two layers retries what, since they are no longer the same one.
- A range without a tab formats the spreadsheet's first tab while the values API writes to the first
  **visible** one. They differ only when the first tab is hidden. Named rather than changed:
  reconciling them means changing what `SheetTabIndex` returns, and that is public.
- The unfinished sentence at the end of the 1.0.9 entry, and a docblock pointing at a method that
  has never existed.

### Testing

- Requests are asserted at the transport now, not at the SDK boundary. Every existing test stubbed
  `Files::listFiles($optParams)` and checked the `q` handed over there, which is one layer too early
  to see a query the client rewrites on the way out — and it is the same blindness that let the wrong
  `setExpiry()` fix pass in 1.0.2. Two tests that read a getter on an object they had just written to
  were rewritten to read the request body.
- 519 tests, up from 467, and the live suite covers the authorization boundary against real grants.

## [1.0.9] - 2026-08-26

### Changed
- **The Google client is built lazily.** Authenticating asks Google for an access token, and that
  happened while the client was being constructed — so every request to a controller holding
  `DriveDocumentService` paid for a token whether it called Drive or not. Measured in a compiled
  container: fetching the service takes 1.9 ms lazily against 242.9 ms eagerly.

  It defers the token request rather than removing it: the first call that reaches Drive pays for it,
  so a request that does use Drive is no faster. What changes is that a request which never touches
  Drive stops paying at all.

  Verified through the paths where proxying could plausibly break, against the live API and beside a
  plain client for comparison: a resource call, `export()`, `exportRevision()` through `authorize()`,
  a resumable upload over 5 MB, and the retry configuration read back. Byte-identical results, and
  the initializer runs exactly once. The client is the right thing to defer and `Drive` is not —
  `Google\Service`'s constructor only type-checks its argument, and the client has no public
  properties, while `Drive` exposes its resources as twenty-two of them

### Fixed
- The live test's changes-feed check had a shorter wait of its own than the two checks beside it,
  and went off on a slow day. Drive's feed lags the way its other indexes do, so it now waits the
  same way they do — a flaky check in a suite meant to be trusted is worse than no check

## [1.0.8] - 2026-08-26

### Documentation
- **Adding a second voter for the `DRIVE_*` attributes does not make the policy stricter, and the
  README had been inviting exactly that.** Symfony's default decision strategy is `affirmative`, so
  one granting voter is enough: a stricter voter of yours votes DENY, this bundle's votes GRANT, and
  access is allowed — whichever order they are registered in. Measured against Symfony's own
  `AccessDecisionManager` and written down as a table, together with what to do instead (replace the
  service definition, keeping one voter on those attributes) and why switching the whole application
  to `unanimous` is the wider change. The worked example was checked against the abstract `Voter` it
  extends, since `DriveVoter` is `final` and the obvious advice — subclass it — does not compile
- **`seesEverything()` grants all four attributes, including `DRIVE_DELETE`.** That was stated, but
  in a sentence easy to read past, and the name invites wiring it to a read-only role: an auditor or
  a support agent who should see the whole drive is the case where it looks harmless. It is a full
  bypass of these checks, not a visibility flag, and the README now says so where the decision is
  made, with the read-everything-change-nothing alternative

## [1.0.7] - 2026-08-26

### Added
- **The live test now covers the authorization boundary**, which was its most conspicuous gap: the
  voter and the resolver are the newest code in the bundle and the most security-critical, and the
  suite that exists to check what mocks cannot did not touch either of them. Seven checks against
  real grants on a real file, decided by `DriveVoter` behind Symfony's own `AccessDecisionManager` —
  a `reader` granted `DRIVE_VIEW` and refused `DRIVE_EDIT`, `DRIVE_SHARE` and `DRIVE_DELETE`; a
  `writer` passing all four; a viewer with no grant refused everything; `seesEverything()`
  bypassing; an empty subject abstained on rather than sent to Drive; and `DriveDocumentResolver`
  raising `AccessDeniedException` for an unreachable id before a controller body would run. 42
  checks with a second identity configured, 30 and four skips without

### Changed
- `tools` is in PHPStan's analysed paths, so a script that exists to check the bundle is itself
  checked on every run of the static-analysis job. Getting it there meant removing the `global`
  counters an analyser cannot follow — they are static properties of one small class now — and
  moving the `exit()` out of the `finally` block, where it discarded whatever the body was raising.
  That last one is the same mistake this script had already found in one of its own checks

## [1.0.6] - 2026-08-26

### Added
- **The live smoke test is part of the repository.** `tools/smoke-test.php` works through the
  bundle's surface against a real Shared Drive and erases everything it creates, including after a
  fatal error. It was a private script until now, which made the one mechanism that catches what
  mocks cannot something nobody else could run — and the two bugs fixed in 1.0.4 are what it found.
  Credentials come from the environment (or a file named by `SMOKE_ENV_FILE`), what it creates can
  be renamed with `SMOKE_PREFIX`, and `SMOKE_SECOND_EMAIL` enables the two sharing checks that need
  a second Google identity

### Fixed
- `setAppProperties()` was annotated `array<string, string|int|float|bool|null>`, which is not what
  PHP hands it: a key like `"2024"` arrives as the integer `2024`. Callers doing nothing wrong got a
  false error from their own PHPStan on code the method handles correctly — the casts inside it exist
  for exactly that case. The annotation admits `array-key` now and says why. Found by bringing the
  live test under static analysis
- A manual **Live smoke test** CI workflow that runs it. Manual rather than automatic on purpose:
  it writes to a real drive and spends Google quota, so it runs when asked. Reports what is missing
  instead of failing obscurely when the secrets are absent

## [1.0.5] - 2026-08-26

### Documentation
- The iframe the README is built around is now backed by a measurement rather than a promise: a
  document's `webViewLink` was framed from another origin over plain HTTP in Chrome, the editor
  rendered, and no `X-Frame-Options` or `frame-ancestors` refusal appeared. Written down with its
  two caveats — the behaviour is undocumented by Google, and only Chrome was checked. Also noted
  that `docs.google.com/spreadsheets/`, the marketing root rather than a document, *does* answer
  `X-Frame-Options: SAMEORIGIN`, so a headers-only check on the wrong URL concludes the opposite
- The two things that decide whether a multi-user deployment is safe — that visibility is opt-in
  behind `ViewerContextInterface`, and that the bundle reports rather than enforces — were a
  paragraph each in the middle of the README. They are now a section of their own near the top,
  where someone evaluating the bundle will actually meet them

## [1.0.4] - 2026-08-26

### Fixed
- **`setExpiry()` never worked.** Drive refuses a `permissions.update` whose body carries no role —
  400, "The permission role field is required" — however little of the grant is being changed, so
  every call failed whatever was asked of it. The grant is now read first and its own role sent
  back unchanged; reading it rather than taking it from the caller is deliberate, since a role
  passed in from a stale `DrivePermission` would quietly change what someone may do
- **Lifting an expiry left it in place.** `setExpiry($fileId, $permissionId, null)` sent a JSON
  null in the body and Drive answered with the old time still on the grant, so access went on
  ending at a date the caller had cleared. `permissions.update` lifts an expiry only when asked to
  in the query, and that parameter is what travels now — never alongside a new expiry, which Drive
  would then drop again

  The 1.0.2 release claimed this fixed; it was not. The test behind that claim asserted the request
  body, which was the right instinct after the release before it, but the body was never where the
  answer lay. Both fixes were found by running the bundle against a real Shared Drive and are
  verified there, not against a mock

### Documentation
- **On a folder, only a reader's grant may expire.** Drive refuses a writer's or a commenter's with
  a 403 whose reason is `cannotSetExpiration`, saying only "Expiration dates cannot be set on this
  item" — the item is fine, it is the pairing it objects to. A file's grant may expire in any of the
  three roles. Measured across every combination rather than read off a page
- **Pinning a revision works on uploaded files only.** On a Google format Drive accepts the call,
  ignores it and answers with the revision unchanged — no error, `keptForever` simply stays false.
  The README had recommended `keepRevision()` for exactly the case it does not cover; for a Sheet or
  a Doc, keeping a version means exporting it
- The package description named the feature set of 0.2 — no revisions, no trash, no export/import,
  no locking, no changes feed, no table formatting. It names what 1.0 actually carries now

## [1.0.3] - 2026-08-26

### Changed
- Table formatting now sends every colour as a `colorStyle`, the field Google asks for since the
  plain `color` fields were deprecated, and the field masks name the twin accordingly — a mask and
  the field it sets have to agree. Affects `style()`, `borders()`, `conditionalFormat()`,
  `bandedRows()` and `tabColor()`. The bundle's own API is unchanged: colours are still `#RRGGBB`
  or `#RGB` strings and the result on the sheet is the same

### Internal
- The tab-title-to-sheet-id lookup, which `SpreadsheetService` and `SheetFormatter` each carried a
  copy of, moved to `SheetTabIndex`. The two copies had already needed the same null guard applied
  separately once, which is the kind of fix that looks done and is not
- Where a `(string)` cast on an array key looks redundant it is now explained: PHP turns a key like
  `"2024"` into the integer `2024`, so a numerically titled tab or app-property key arrives as an
  int however the array is annotated
- The class docblocks state what the README already did — that `Google\Service\Exception` is
  passed through rather than wrapped, and only the cases the bundle can explain better get an
  exception of their own

## [1.0.2] - 2026-08-26

### Fixed
- `setExpiry($fileId, $permissionId, null)` did not lift the expiry. The Google client drops a PHP
  `null` when it serialises a request body, so the field never reached Drive, and `permissions.update`
  being a PATCH, an absent field means "keep the old value" — the grant kept expiring when it always
  had. The placeholder the client provides for a JSON `null` is now used, and the test reads the
  serialised body rather than the object's getter, which is how the omission went unnoticed
- A grant with an expiry could outlive it in the sharing cache: an entry was kept for the full
  `permission_cache.ttl` however soon the grant ran out, so a viewer kept their access for up to
  the TTL after Drive had dropped it. The entry now lives no longer than the soonest expiry among
  the grants it holds, and a grant whose time is already up is ignored even while Drive still
  lists it
- `exportRevision()` streamed an error page as the document: the export links are fetched through
  the client's own authorised HTTP client, which is built with `http_errors` off, so a 403 or a
  5xx came back as the revision's content. Any status of 400 or above is now raised as a Google
  exception carrying Drive's own message and reason
- `changesSince()` reported changes to the Shared Drive itself — a rename, a new restriction — as
  a `DriveChange` with an empty `fileId`, and cleared a cache entry under that empty key. The feed
  carries them with a `changeType` of `drive`; they are now skipped, so every change is about a file.
  That field is also asked for now: an unrequested field comes back null, so the check on it was
  dead code quietly leaning on the empty-`fileId` test beside it — the two conditions are separate
  on purpose and each is tested on its own
- `setExpiry()` explains an inherited grant with `InheritedPermissionException`, as `revoke()`
  already did, instead of passing on Drive's bare 403
- `DriveVoter` abstains on an empty string as the subject instead of asking Google about the id
  `""` and earning a 400

### Changed
- `exportRevision()` without a MIME type refuses a revision that offers several formats, naming
  them, instead of taking whichever link Google listed first. Google's order is not a contract;
  a revision offering one format still needs no choice, and an uploaded file's revision never did.

  This is the one entry worth a second look before upgrading, because it refuses a call that used
  to be accepted. It sits here as a fix rather than waiting for a major release: what it replaces
  was an arbitrary pick from an order Google never promised, so it produced whichever format
  happened to be first — nothing a caller could have depended on deliberately, and quietly wrong
  when it was not the one they meant. Name the format and the call behaves as it always should
  have
- Documentation: `changesSince()` is the drive's feed and is not filtered per viewer; the `id`
  fallback of `DriveDocumentResolver` on routes where `{id}` is something else; what Drive
  refuses to `deleteRevision()`; the sharing cache and expiring grants; `DRIVE_EDIT` also
  covers `lock()` and the application's own metadata

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

[Unreleased]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.1.3...HEAD
[1.1.3]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.9...v1.1.0
[1.0.9]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.8...v1.0.9
[1.0.8]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.7...v1.0.8
[1.0.7]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.6...v1.0.7
[1.0.6]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v1.0.1...v1.0.2
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
