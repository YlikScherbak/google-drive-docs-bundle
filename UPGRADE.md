# Upgrading

Every version before 1.0 was a `0.x`, and Composer treats those as potentially breaking: a
constraint of `^0.9.0` will not pick up `0.10` or `1.0` on its own. So each step below was a
deliberate constraint bump, and this file says what each one asked of you.

From 1.0 onwards that changes — see [The promise from 1.0](#the-promise-from-10) at the end.

## 1.0.x → 1.1.0

`^1.0` picks this up on its own, and nothing was removed. But it corrects four behaviours, and two
of them can change who reaches what — read those before deploying.

### `+tag` is no longer folded away outside Gmail

A grant is matched to a viewer by e-mail, and the `+tag` suffix used to be stripped on every domain.
It is now stripped only on `gmail.com` and `googlemail.com`, which are the domains Google itself
ignores it on.

If your users sign in as `alice+something@your-domain.com` and the Drive grant is to
`alice@your-domain.com`, that match no longer happens and they lose access. Grant the address they
actually sign in with. The change is there because the old behaviour worked in both directions:
`alice+intruder@your-domain.com` matched a grant to `alice@your-domain.com`.

### A Google outage on a single item now raises instead of denying

`canAccess()` and `roleOf()` used to answer "no" when the sharing lookup failed, which turned an
outage into a denial the caller could not tell from a real one. They now let the
`Google\Service\Exception` through.

If you call either directly and treated `false` as "not shared", you may now see an exception where
you saw a denial. Listings are unchanged: they still hide what they cannot check.

### Sharing inherited from a folder is read from the folder

Nothing to change, but the call pattern differs: a viewer whose grant sits on a folder is no longer
answered by the child's cached entry, so the walk goes up to that folder. It is why revoking on a
folder now takes effect immediately. The extra calls are more than paid back by the walk being read
once per request instead of once per question.

### The drive id and the empty string as a parent

`canAccess($driveId)` answers `true` — the drive id names the root, as the README always said, and
asking about it directly used to refuse every call that named it. An empty string now counts as no
parent everywhere one is taken, rather than meaning something slightly different on each path.

### New settings, all optional

```yaml
google_drive_docs:
    http:
        timeout: 30.0          # was: no limit at all
        connect_timeout: 10.0
```

Both are new defaults rather than new options with the old behaviour: before this, a request with no
answer waited for ever. Set either to `0` for that back.

A PSR-3 logger can be passed as the service's last constructor argument, and it is worth doing — it
is where you find out that a document was hidden from a listing because the sharing lookup failed
rather than because it was not shared.

## 0.9.x → 1.0.0

Three things to change, and only the first is likely to affect you.

### `delete()` is gone — use `deleteForever()` or `trash()`

Deprecated since 0.3.0 and removed as promised. It erased an item for good, which its name never
said:

```php
- $drive->delete($fileId);
+ $drive->deleteForever($fileId);   // same behaviour: gone for good
+ $drive->trash($fileId);           // probably what a UI's delete button should do
```

If a trash-can button in your interface still calls the first of those, the second is almost
certainly what your users expect — see "How the trash behaves" in the README.

### `MAX_UPLOAD_BYTES` is gone

It stopped describing the largest upload in 0.7.0, when resumable uploads removed the 5 MB
ceiling, and was deprecated then:

```php
- DriveDocumentService::MAX_UPLOAD_BYTES     // was: the largest upload
+ DriveDocumentService::MULTIPART_LIMIT      // the point where the two upload paths part
```

For a limit of your own, set `upload.max_bytes` in the configuration instead.

### Symfony 6.0–6.3 are no longer claimed

`composer.json` asked for `^6.0 || ^7.0` while CI only ever verified 6.4 and 7. For a release
that comes with a compatibility promise, claiming support nothing checks is worse than narrowing
it, so the constraint is now `^6.4 || ^7.0`. Nothing changed in the code; the claim now matches
what is tested.

### New, and worth knowing about

- **`is_granted()` works** on drive items through `DriveVoter` — `DRIVE_VIEW`, `DRIVE_EDIT`,
  `DRIVE_SHARE`, `DRIVE_DELETE`. Install `symfony/security-core` and it registers itself. This is
  where "the bundle reports, it does not enforce" turns into a decision you can read and replace.
- **`DriveDocument` resolves straight into a controller argument** from a route parameter.
- **`forDrive($driveId)`** returns the same service pointed at another Shared Drive, carrying
  every other setting over.

## 0.8.x → 0.9.0

Additive. Worth adopting rather than required:

- `changesSince()` picks up work done directly in Google and drops the affected item's cached
  sharing, which is what the README used to apologise for. Store the returned token yourself.
- `bin/console google-drive-docs:check` verifies the whole setup; run it once after upgrading.

## 0.7.x → 0.8.0

Additive: version history (`listRevisions()`, `keepRevision()`, `exportRevision()`). There is no
rollback in Drive API v3, so recovering old content means exporting a revision and importing it —
the README carries the recipe.

## 0.7.0 → 0.7.1

**Fixes only, and worth taking if you import files over 5 MB** — those were broken in 0.7.0. If
you are on 0.7.0, do not skip straight to a later minor without reading this: `^0.7.0` picks 0.7.1
up on its own.

## 0.6.x → 0.7.0

One change of meaning:

**`UploadTooLargeException` now fires only when you set a limit.** Resumable uploads removed the
5 MB ceiling, so a file over it is uploaded rather than refused. If you caught that exception to
tell users "too big for this integration", either set `upload.max_bytes` to the size you actually
want to allow, or stop expecting the exception.

## 0.5.x → 0.6.0

Additive: the formatting pass (`format()`), tab management, `SheetRange`.

## 0.4.x → 0.5.0

Additive: `roleOf()`. One operational note — the sharing cache stores a role per address now, so
its key carries a version and entries written by 0.4.0 are missed rather than misread. Expect one
cold lookup per item after upgrading, nothing more.

## 0.3.x → 0.4.0

Additive: `SpreadsheetService`. The Sheets API must be enabled for your Google Cloud project,
which the README has always asked for but which had no consequence until now.

## 0.3.0 → 0.3.1

**A security fix — take it.** `grant()`, `grantToGroup()`, `revoke()` and `listPermissions()` did
not check the viewer's access, so in an application with a restrictive `ViewerContextInterface`
anyone holding a file id could share a document with themselves, revoke other people's access, or
read who a document was shared with. Applications where `seesEverything()` is true were never
exposed.

One thing to check after upgrading: if you share a just-created document with its creator — the
"creator gets access" pattern — that grant is now refused for a document created in the drive
root, because the creator cannot yet reach it. Use `grantAsService()` there.

## 0.2.x → 0.3.0

- **`delete()` became deprecated** (see 1.0 above). Its behaviour did not change.
- A 403 on a permanent delete became `InsufficientDriveRoleException` rather than a raw
  `Google\Service\Exception`. Code catching the latter around `delete()` should catch the new one
  too.
- `DriveDocument` gained fields, so `toArray()` gained keys. Anything asserting its exact shape
  needs updating.

## 0.1.x → 0.2.0

`ViewerContextInterface` gained `getViewerGroups(): array`. Implementations must add it; return an
empty array if you do not share with Google groups.

## The promise from 1.0

From 1.0 the public API follows semantic versioning properly:

- **Patch** releases fix behaviour without changing any signature.
- **Minor** releases add. New methods, new optional arguments at the end of existing ones, new
  optional constructor arguments, new fields at the end of a model's constructor, new
  configuration keys defaulting to what happened before. Existing calls keep working.
- **Major** releases are the only ones that remove or change what exists, and they arrive with a
  section in this file saying what to do.

Two things sit just outside that promise, and it is fairer to name them than to imply otherwise.

**A model's `toArray()` may gain keys in a minor release.** It exists to be serialised, and the
alternative — freezing the shape until a major — would mean a new field waiting a year to be
visible. Code that asserts an exact array shape should expect additions; code that reads keys it
knows will not notice.

**Google's own behaviour is not ours to promise.** Limits change, error text changes, fields turn
up. Where a limit is Google's, this bundle says so and lets Google enforce it rather than mirroring
a number that can go stale — see `MAX_BATCH_RANGES` and the grid bounds in `SheetRange` for how
that reads in practice.
