<?php

declare(strict_types=1);

/**
 * Live smoke test against a real Shared Drive.
 *
 * The unit suite mocks Google's own classes, so it can only confirm that the bundle sends what
 * the bundle thinks it should send. This asks Drive instead. It exists because it earns its
 * keep: it has found bugs no mock could — a permissions.update Drive refuses without a role, an
 * expiry a JSON null never lifted, a field mask Sheets quietly ignored.
 *
 * What it covers are the places a mock can lie: the field masks Sheets actually honours, whether
 * an export link fetched outside the service layer returns bytes or an error page, whether a
 * resumable upload's chunking is accepted, whether the changes feed names the file id the cache
 * invalidation keys on, and which pairings of item and role Drive lets an expiry sit on.
 *
 * IT CREATES AND DELETES REAL OBJECTS in the drive you point it at. Everything it makes carries
 * the prefix and lives inside one folder, and everything is erased for good in the finally
 * block, including after a fatal error. The run prints what it created and what it erased, and
 * names anything it could not — read that list. Point it at a drive you are willing to have
 * written to, not at one holding anything you would miss.
 *
 *     GOOGLE_CLIENT_ID=... GOOGLE_CLIENT_SECRET=... GOOGLE_SHARED_DRIVE_ID=... \
 *     GOOGLE_OAUTH_REFRESH_TOKEN=... php tools/smoke-test.php
 *
 * Or put those four in a file and point SMOKE_ENV_FILE at it; the environment still wins.
 *
 * SMOKE_PREFIX        renames what it creates (default "smoke_test_")
 * SMOKE_SECOND_EMAIL  a second Google address, for the two sharing checks that need one — a
 *                     grant cannot be made to the account that already owns the drive. Without
 *                     it those two report as skipped. The address is granted access and then
 *                     revoked, and no notification e-mail is sent.
 *
 * Credentials are read into the process and nowhere else: never echoed, never written to disk.
 */

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Controller\DriveDocumentResolver;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Security\DriveVoter;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Google\Service\Drive;
use Google\Service\Sheets;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;

require __DIR__ . '/../vendor/autoload.php';

define('PREFIX', getenv('SMOKE_PREFIX') ?: 'smoke_test_');
const MIME_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

// ---------------------------------------------------------------- environment

/**
 * The four credentials, from the environment first and from an optional file second.
 *
 * A file is a convenience for repeated local runs; anywhere that already has the variables set,
 * CI included, needs no file at all.
 *
 * @return array<string, string>
 */
function loadEnv(?string $path): array
{
    $values = [];

    if ($path === null) {
        return $values;
    }

    if (!is_file($path)) {
        fwrite(STDERR, "SMOKE_ENV_FILE points at nothing: {$path}\n");
        exit(1);
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

$env     = loadEnv(getenv('SMOKE_ENV_FILE') ?: null);
$missing = [];

foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_SHARED_DRIVE_ID', 'GOOGLE_OAUTH_REFRESH_TOKEN'] as $required) {
    // The environment wins: a file is for convenience, not a source of truth.
    $env[$required] = getenv($required) ?: ($env[$required] ?? '');

    if ($env[$required] === '') {
        $missing[] = $required;
    }
}

if ($missing !== []) {
    fwrite(STDERR, sprintf(
        "Nothing to talk to Drive with. Missing: %s.\n"
        . "Set them in the environment, or put them in a file and point SMOKE_ENV_FILE at it.\n",
        implode(', ', $missing)
    ));
    exit(1);
}

$secondEmail = getenv('SMOKE_SECOND_EMAIL') ?: null;

// ---------------------------------------------------------------- reporting

/**
 * What the run has learned so far.
 *
 * Static rather than the `global` these were before, so a static analyser can follow the writes:
 * a script that exists to check the bundle should itself be checkable.
 */
final class Tally
{
    /** @var string[] */
    public static array $passed = [];

    /** @var string[] */
    public static array $failed = [];

    /** @var string[] */
    public static array $skipped = [];

    /** @var array<string, string> id => label, for the cleanup to walk in reverse */
    public static array $created = [];
}

/** Runs one check; a failure is recorded and the run continues. */
function check(string $name, callable $body): mixed
{
    try {
        $result = $body();
        Tally::$passed[] = $name;
        echo "  \u{2713} {$name}\n";

        return $result;
    } catch (Throwable $e) {
        Tally::$failed[] = $name . ' — ' . $e->getMessage();
        echo "  \u{2717} {$name}\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";

        return null;
    }
}

function skip(string $name, string $why): void
{
    Tally::$skipped[] = $name . ' — ' . $why;
    echo "  \u{2013} {$name} (skipped: {$why})\n";
}

/**
 * Retries a condition against Drive's eventually consistent indexes.
 *
 * The trash listing and the appProperties index both took about 4 seconds to catch up when
 * measured, so a single immediate call is a coin toss rather than a test.
 */
function eventually(callable $condition, int $tries = 10, int $pause = 4): bool
{
    for ($attempt = 0; $attempt < $tries; ++$attempt) {
        if ($condition()) {
            return true;
        }

        sleep($pause);
    }

    return false;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------- wiring

/** Mutable so a check can change who the bundle thinks is browsing. */
final class SmokeViewer implements ViewerContextInterface
{
    public ?string $email = null;
    public bool $everything = true;
    /** @var string[] */
    public array $groups = [];

    public function getViewerEmail(): ?string
    {
        return $this->email;
    }

    public function seesEverything(): bool
    {
        return $this->everything;
    }

    public function getViewerGroups(): array
    {
        return $this->groups;
    }
}

$client = (new GoogleClientFactory(
    $env['GOOGLE_CLIENT_ID'],
    $env['GOOGLE_CLIENT_SECRET'],
    $env['GOOGLE_OAUTH_REFRESH_TOKEN']
))->create();

// The limited-access check needs a field the bundle does not expose, so it reaches for the raw
// service. Everything else in this file goes through the bundle.
$GLOBALS['smokeClient'] = $client;

$viewer = new SmokeViewer();
// text/plain is here so the trash check can use the imported file: listTrash() filters by
// document_mime_types, and a type left out of it is excluded from the listing by design.
$drive  = new DriveDocumentService(
    new Drive($client),
    $viewer,
    $env['GOOGLE_SHARED_DRIVE_ID'],
    [MIME_SPREADSHEET, 'text/plain']
);
$sheetsApi = new Sheets($client);
$sheets    = new SpreadsheetService($sheetsApi, $drive);

$stamp     = date('Ymd_His');
$folderId  = null;
$tempFiles = [];

/** Records something the run created, so the cleanup cannot forget it. */
function born(string $id, string $label): string
{
    Tally::$created[$id] = $label;

    return $id;
}

echo "Live smoke test — prefix \"" . PREFIX . "\", stamp {$stamp}\n";
echo str_repeat('-', 72) . "\n";

try {
    // ------------------------------------------------------------ reachability
    echo "\nReachability\n";

    check('the drive answers a root listing', static function () use ($drive) {
        $drive->listFolder();

        return null;
    });

    // ------------------------------------------------------------ the workspace
    echo "\nFolder and file\n";

    $folder = check('createFolder()', static function () use ($drive, $stamp) {
        $doc = $drive->createFolder(PREFIX . 'folder_' . $stamp);
        assertTrue($doc->id !== '', 'no id came back');

        return $doc;
    });

    if ($folder === null) {
        throw new RuntimeException('Without the folder there is nothing to put anything in.');
    }

    $folderId = born($folder->id, 'folder ' . $folder->name);

    $sheet = check('createDocument() makes a spreadsheet in it', static function () use ($drive, $folderId, $stamp) {
        $doc = $drive->createDocument(PREFIX . 'sheet_' . $stamp, $folderId, MIME_SPREADSHEET);
        assertTrue($doc->mimeType === MIME_SPREADSHEET, 'wrong mime: ' . $doc->mimeType);

        return $doc;
    });

    $sheetId = $sheet !== null ? born($sheet->id, 'sheet ' . $sheet->name) : null;

    check('listFolder() finds it inside the folder', static function () use ($drive, $folderId, $sheetId) {
        $ids = array_map(static fn ($d) => $d->id, $drive->listFolder($folderId));
        assertTrue(in_array($sheetId, $ids, true), 'the new sheet is not in the listing');

        return null;
    });

    check('listFolderPage() reports a page', static function () use ($drive, $folderId) {
        $page = $drive->listFolderPage($folderId, null, 10);
        assertTrue($page->items !== [], 'an empty page for a folder with a file in it');

        return null;
    });

    // ------------------------------------------------------------ app properties
    echo "\nApplication metadata\n";

    if ($sheetId !== null) {
        check('setAppProperties() survives a numerically titled key', static function () use ($drive, $sheetId) {
            // "2024" is an int key by the time PHP hands it over — the case the (string) casts guard.
            $drive->setAppProperties($sheetId, ['smoke_marker' => $sheetId, '2024' => 'numeric-key']);
            $read = $drive->appProperties($sheetId);

            assertTrue(($read['smoke_marker'] ?? null) === $sheetId, 'marker did not come back');
            assertTrue(($read['2024'] ?? null) === 'numeric-key', 'the numeric key did not come back');

            return null;
        });

        check('findByAppProperty() finds it again', static function () use ($drive, $sheetId) {
            // The appProperties index is eventually consistent — about 4s when measured.
            assertTrue(
                eventually(static function () use ($drive, $sheetId): bool {
                    $ids = array_map(static fn ($d) => $d->id, $drive->findByAppProperty('smoke_marker', $sheetId));

                    return in_array($sheetId, $ids, true);
                }),
                'the marker never matched, even after 40s'
            );

            return null;
        });
    }

    // ------------------------------------------------------------ sheet values
    echo "\nSheet values\n";

    $firstTab = null;

    if ($sheetId !== null) {
        $firstTab = check('listTabs() names the tab the account actually got', static function () use ($sheets, $sheetId) {
            $tabs = $sheets->listTabs($sheetId);
            assertTrue($tabs !== [], 'a spreadsheet with no tabs');

            return $tabs[0]['title'];
        });
    }

    if ($sheetId !== null && $firstTab !== null) {
        check('write() RAW keeps a formula as text', static function () use ($sheets, $sheetId, $firstTab) {
            $sheets->write($sheetId, SpreadsheetService::range($firstTab, 'A1:B2'), [
                ['=SUM(1,2)', '007'],
                ['plain', '42'],
            ]);

            $back = $sheets->read($sheetId, SpreadsheetService::range($firstTab, 'A1:B1'));
            assertTrue(($back[0][0] ?? null) === '=SUM(1,2)', 'RAW evaluated the formula: ' . var_export($back[0][0] ?? null, true));
            assertTrue(($back[0][1] ?? null) === '007', 'RAW dropped the leading zeros: ' . var_export($back[0][1] ?? null, true));

            return null;
        });

        check('append() adds a row after the used range', static function () use ($sheets, $sheetId, $firstTab) {
            $sheets->append($sheetId, [['appended', '1']], $firstTab);
            $back = $sheets->read($sheetId, SpreadsheetService::range($firstTab, 'A1:B10'));
            assertTrue(count($back) >= 3, 'append did not grow the range, got ' . count($back) . ' rows');

            return null;
        });

        check('writeMany() and readMany() agree', static function () use ($sheets, $sheetId, $firstTab) {
            $sheets->writeMany($sheetId, [
                SpreadsheetService::range($firstTab, 'D1') => [['d-one']],
                SpreadsheetService::range($firstTab, 'E1') => [['e-one']],
            ]);

            $back = $sheets->readMany($sheetId, [
                SpreadsheetService::range($firstTab, 'D1'),
                SpreadsheetService::range($firstTab, 'E1'),
            ]);

            $values = array_map(static fn ($rows) => $rows[0][0] ?? null, array_values($back));
            assertTrue($values === ['d-one', 'e-one'], 'batch read gave ' . json_encode($values));

            return null;
        });

        check('a tab called "Q3" is quoted correctly in A1 notation', static function () use ($sheets, $sheetId) {
            // A cell-like tab name is the case range() has to quote or Sheets reads it as a cell.
            $sheets->addTab($sheetId, 'Q3');
            $sheets->write($sheetId, SpreadsheetService::range('Q3', 'A1'), [['quarter']]);
            $back = $sheets->read($sheetId, SpreadsheetService::range('Q3', 'A1'));
            assertTrue(($back[0][0] ?? null) === 'quarter', 'the value did not land on the Q3 tab');

            return null;
        });

        check('clear() empties a range', static function () use ($sheets, $sheetId, $firstTab) {
            $sheets->clear($sheetId, SpreadsheetService::range($firstTab, 'D1:E1'));
            $back = $sheets->read($sheetId, SpreadsheetService::range($firstTab, 'D1:E1'));
            assertTrue($back === [], 'the range still holds ' . json_encode($back));

            return null;
        });

        check('renameTab() and deleteTab()', static function () use ($sheets, $sheetId) {
            $sheets->addTab($sheetId, 'scratch');
            $sheets->renameTab($sheetId, 'scratch', 'scratch_renamed');
            $titles = array_map(static fn ($t) => $t['title'], $sheets->listTabs($sheetId));
            assertTrue(in_array('scratch_renamed', $titles, true), 'rename did not take');

            $sheets->deleteTab($sheetId, 'scratch_renamed');
            $titles = array_map(static fn ($t) => $t['title'], $sheets->listTabs($sheetId));
            assertTrue(!in_array('scratch_renamed', $titles, true), 'delete did not take');

            return null;
        });
    }

    // ------------------------------------------------------------ formatting
    echo "\nFormatting (the colorStyle field masks of 1.0.3)\n";

    if ($sheetId !== null) {
        check('format() applies and Sheets honours the colorStyle masks', static function () use ($sheets, $sheetsApi, $sheetId) {
            $sheets->format($sheetId)
                ->style('Q3!A1:B1', bold: true, background: '#FFD5D5', color: '#003366', horizontalAlign: 'CENTER')
                ->borders('Q3!A1:B2', color: '#888888')
                ->bandedRows('Q3!A1:B5', '#FFFFFF', '#F3F3F3')
                ->numberFormat('Q3!B1:B5', '#,##0.00')
                ->freeze('Q3', rows: 1)
                ->tabColor('Q3', '#00FF00')
                ->apply();

            // Read the grid back: effectiveFormat is what Sheets resolved, so a mask that missed
            // its field shows up here as an unchanged colour.
            // userEnteredFormat, not effectiveFormat: bandedRows() covers the same range and
            // banding wins in the format Sheets resolves, so the effective background would be
            // the band's white however well the cell mask worked. Verified against Drive.
            $grid = $sheetsApi->spreadsheets->get($sheetId, [
                'ranges'          => "'Q3'!A1:A1",
                'includeGridData' => true,
                'fields'          => 'sheets(properties(title,tabColorStyle),data(rowData(values('
                    . 'userEnteredFormat(backgroundColorStyle,textFormat(bold,foregroundColorStyle))))))',
            ]);

            $sheet = ($grid->getSheets() ?? [])[0] ?? null;
            assertTrue($sheet !== null, 'no sheet came back');

            $cell = (((($sheet->getData() ?? [])[0] ?? null)?->getRowData() ?? [])[0] ?? null)?->getValues()[0] ?? null;
            assertTrue($cell !== null, 'no cell data came back');

            $format = $cell->getUserEnteredFormat();
            assertTrue($format !== null, 'the cell has no entered format');

            $bg = $format->getBackgroundColorStyle()?->getRgbColor();
            assertTrue($bg !== null, 'backgroundColorStyle did not land — the field mask missed');
            assertTrue(
                abs(($bg->getRed() ?? 0.0) - 1.0) < 0.01 && abs(($bg->getGreen() ?? 0.0) - 0.835) < 0.02,
                sprintf('background is r=%s g=%s b=%s, expected #FFD5D5', $bg->getRed(), $bg->getGreen(), $bg->getBlue())
            );

            assertTrue($format->getTextFormat()?->getBold() === true, 'bold did not land');

            $fg = $format->getTextFormat()?->getForegroundColorStyle()?->getRgbColor();
            assertTrue($fg !== null, 'foregroundColorStyle did not land — the field mask missed');

            $tab = $sheet->getProperties()?->getTabColorStyle()?->getRgbColor();
            assertTrue($tab !== null, 'tabColorStyle did not land — the field mask missed');
            assertTrue(abs(($tab->getGreen() ?? 0.0) - 1.0) < 0.01, 'the tab colour is not green');

            return null;
        });
    }

    // ------------------------------------------------------------ export
    echo "\nExport\n";

    if ($sheetId !== null) {
        check('export() as xlsx returns real bytes', static function () use ($drive, $sheetId) {
            $export = $drive->export($sheetId, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $bytes  = (string) $export->stream;
            assertTrue(strlen($bytes) > 1000, 'only ' . strlen($bytes) . ' bytes came back');
            assertTrue(str_starts_with($bytes, 'PK'), 'not a zip container, so not an xlsx');

            return null;
        });

        check('export() as csv returns the values, not an error page', static function () use ($drive, $sheetId) {
            $bytes = (string) $drive->export($sheetId, 'text/csv')->stream;
            assertTrue(!str_starts_with(ltrim($bytes), '<'), 'an HTML page came back instead of csv');
            assertTrue(str_contains($bytes, '=SUM(1,2)') || str_contains($bytes, 'plain'), 'the csv has none of our values');

            return null;
        });
    }

    // ------------------------------------------------------------ revisions
    echo "\nRevisions\n";

    if ($sheetId !== null) {
        $revisionId = check('listRevisions() reports at least one', static function () use ($drive, $sheetId) {
            $revisions = $drive->listRevisions($sheetId);
            assertTrue($revisions !== [], 'no revisions at all');

            return $revisions[count($revisions) - 1]->id;
        });

        if ($revisionId !== null) {
            check('revision() reads one back', static function () use ($drive, $sheetId, $revisionId) {
                $revision = $drive->revision($sheetId, $revisionId);
                assertTrue($revision->id === $revisionId, 'a different revision came back');

                return null;
            });

            check('exportRevision() streams bytes, not an error page', static function () use ($drive, $sheetId, $revisionId) {
                // The export link is fetched through the client's authorised HTTP client, which has
                // http_errors off — the path where a 403 used to arrive as the document.
                $bytes = (string) $drive->exportRevision($sheetId, $revisionId, 'text/csv')->stream;
                assertTrue($bytes !== '', 'nothing came back');
                assertTrue(!str_starts_with(ltrim($bytes), '<'), 'an HTML error page came back as the revision');

                return null;
            });

            check('exportRevision() without a format refuses when several are offered', static function () use ($drive, $sheetId, $revisionId) {
                try {
                    $drive->exportRevision($sheetId, $revisionId);
                } catch (InvalidArgumentException $e) {
                    assertTrue(str_contains($e->getMessage(), 'text/csv'), 'the message does not name the formats');

                    return null;
                }

                throw new RuntimeException('it picked a format on its own');
            });

            check('keepRevision() is ignored on a Google format, as Drive intends', static function () use ($drive, $sheetId, $revisionId) {
                // Pinning is for uploaded files; a Sheet accepts the call and stays unpinned.
                $kept = $drive->keepRevision($sheetId, $revisionId, true);
                assertTrue(!$kept->keptForever, 'a spreadsheet revision reports itself pinned now — recheck the docs');

                return null;
            });
        }
    }

    // ------------------------------------------------------------ changes feed
    echo "\nChanges feed\n";

    if ($sheetId !== null) {
        check('changesSince() names the file id the cache keys on', static function () use ($drive, $sheetId, $stamp) {
            $token = $drive->startPageToken();
            $drive->rename($sheetId, PREFIX . 'sheet_' . $stamp . '_renamed');

            // The feed lags like the other Drive indexes do, so it waits the same way they do.
            // It had its own shorter loop until that loop went off on a slow day, which is what a
            // flaky check in a suite meant to be trusted looks like.
            $changes = null;

            assertTrue(
                eventually(static function () use ($drive, $token, &$changes): bool {
                    $changes = $drive->changesSince($token);

                    return $changes->changes !== [];
                }),
                'the feed stayed empty'
            );

            foreach ($changes->changes as $change) {
                assertTrue($change->fileId !== '', 'a change came back with an empty fileId — a drive-level entry leaked');
            }

            $ids = array_map(static fn ($c) => $c->fileId, $changes->changes);
            assertTrue(in_array($sheetId, $ids, true), 'our rename is not in the feed: ' . implode(', ', $ids));
            assertTrue($changes->nextToken !== '', 'no token to carry on from');

            return null;
        });
    }

    // ------------------------------------------------------------ locking
    echo "\nContent restrictions\n";

    if ($sheetId !== null) {
        check('lock() and unlock() round-trip', static function () use ($drive, $sheetId) {
            $locked = $drive->lock($sheetId, 'smoke test');
            assertTrue($locked->locked, 'the file did not lock');
            assertTrue($locked->lockReason === 'smoke test', 'the reason did not stick: ' . var_export($locked->lockReason, true));

            $unlocked = $drive->unlock($sheetId);
            assertTrue(!$unlocked->locked, 'the file did not unlock');

            return null;
        });
    }

    // ------------------------------------------------------------ copy, template
    echo "\nCopying\n";

    if ($sheetId !== null) {
        $copy = check('copy()', static function () use ($drive, $sheetId, $folderId, $stamp) {
            $doc = $drive->copy($sheetId, PREFIX . 'copy_' . $stamp, $folderId);
            assertTrue($doc->id !== $sheetId, 'the copy has the original id');

            return $doc;
        });

        if ($copy !== null) {
            born($copy->id, 'copy ' . $copy->name);
        }

        $fromTemplate = check('createFromTemplate()', static function () use ($drive, $sheetId, $folderId, $stamp) {
            return $drive->createFromTemplate($sheetId, PREFIX . 'from_template_' . $stamp, $folderId);
        });

        if ($fromTemplate !== null) {
            born($fromTemplate->id, 'from template ' . $fromTemplate->name);
        }

        check('copy() refuses a folder', static function () use ($drive, $folderId) {
            try {
                $drive->copy($folderId);
            } catch (Throwable $e) {
                assertTrue(
                    str_contains(get_class($e), 'NotCopyable'),
                    'a different exception: ' . get_class($e) . ' ' . $e->getMessage()
                );

                return null;
            }

            throw new RuntimeException('it copied a folder');
        });
    }

    // ------------------------------------------------------------ import
    echo "\nImport\n";

    $smallPath = sys_get_temp_dir() . '/' . PREFIX . 'small_' . $stamp . '.txt';
    file_put_contents($smallPath, "one,two\nthree,four\n");
    $tempFiles[] = $smallPath;

    $small = check('import() a small file (multipart)', static function () use ($drive, $folderId, $smallPath) {
        $doc = $drive->import($smallPath, PREFIX . 'imported_small', $folderId, false);
        assertTrue($doc->id !== '', 'no id came back');

        return $doc;
    });

    if ($small !== null) {
        born($small->id, 'imported small ' . $small->name);
    }

    // Over MULTIPART_LIMIT so the resumable path runs, and not a multiple of the chunk size so
    // the last chunk is a partial one — with one byte over, the case the tail handling covers.
    $bigPath = sys_get_temp_dir() . '/' . PREFIX . 'big_' . $stamp . '.bin';
    $bigSize = 5 * 1024 * 1024 + 262144 + 1;
    $handle  = fopen($bigPath, 'wb');
    $block   = str_repeat('borsche-smoke-', 1024);

    for ($written = 0; $written < $bigSize; $written += strlen($block)) {
        fwrite($handle, substr($block, 0, min(strlen($block), $bigSize - $written)));
    }

    fclose($handle);
    $tempFiles[] = $bigPath;

    $big = check(
        sprintf('import() a %.2f MB file (resumable, partial last chunk)', $bigSize / 1048576),
        static function () use ($drive, $folderId, $bigPath, $bigSize) {
            $doc = $drive->import($bigPath, PREFIX . 'imported_big', $folderId, false);
            assertTrue($doc->id !== '', 'no id came back');
            assertTrue(
                $doc->size === $bigSize,
                sprintf('Drive stored %s bytes, sent %d', var_export($doc->size, true), $bigSize)
            );

            return $doc;
        }
    );

    if ($big !== null) {
        born($big->id, 'imported big ' . $big->name);
    }

    // ------------------------------------------------------------ trash
    echo "\nTrash\n";

    if ($small !== null) {
        check('trash() then restore()', static function () use ($drive, $small) {
            $trashed = $drive->trash($small->id);
            assertTrue($trashed->trashed, 'the flag did not set');

            // The trash listing lags the same way the appProperties index does.
            assertTrue(
                eventually(static function () use ($drive, $small): bool {
                    $ids = array_map(static fn ($d) => $d->id, $drive->listTrash());

                    return in_array($small->id, $ids, true);
                }),
                'it never appeared in the trash listing, even after 40s'
            );

            $restored = $drive->restore($small->id);
            assertTrue(!$restored->trashed, 'the flag did not clear');

            return null;
        });
    }

    // ------------------------------------------------------------ sharing
    echo "\nSharing\n";

    if ($secondEmail === null) {
        skip('roleOf() inherits from a parent folder', 'set SMOKE_SECOND_EMAIL to a second Google address');
        skip('setExpiry(..., null) lifts an expiry', 'set SMOKE_SECOND_EMAIL to a second Google address');
    } elseif ($sheetId !== null) {
        $permission = check('grant() on the folder', static function () use ($drive, $folderId, $secondEmail) {
            return $drive->grant($folderId, $secondEmail, 'writer');
        });

        check('roleOf() sees the folder grant on the file inside it', static function () use ($drive, $viewer, $sheetId, $secondEmail) {
            $viewer->everything = false;
            $viewer->email      = $secondEmail;

            try {
                assertTrue($drive->canAccess($sheetId), 'the viewer cannot reach the file');
                $role = $drive->roleOf($sheetId);
                assertTrue($role === 'writer', 'roleOf gave ' . var_export($role, true) . ', expected writer');
            } finally {
                $viewer->everything = true;
                $viewer->email      = null;
            }

            return null;
        });

        check('an expiring folder grant is refused for a writer, as Drive intends', static function () use ($drive, $folderId, $secondEmail) {
            try {
                $drive->grant($folderId, $secondEmail, 'writer', 'user', new DateTimeImmutable('+3 days'));
            } catch (Throwable $e) {
                assertTrue(
                    str_contains($e->getMessage(), 'cannotSetExpiration'),
                    'a different refusal: ' . $e->getMessage()
                );

                return null;
            }

            throw new RuntimeException('Drive accepted it — the restriction has changed, recheck the docs');
        });

        if ($permission !== null) {
            check('revoke()', static function () use ($drive, $folderId, $permission) {
                $drive->revoke($folderId, $permission->id);
                $ids = array_map(static fn ($p) => $p->id, $drive->listPermissions($folderId));
                assertTrue(!in_array($permission->id, $ids, true), 'the grant is still listed');

                return null;
            });
        }

        // Last, and only once the folder grant is gone: while it stands, a grant on a file
        // inside the folder comes back as the inherited one, which Drive will not let anyone
        // change on the child. The expiry goes on a file's own grant either way — Drive allows
        // an expiring folder grant for a reader alone.
        check('setExpiry() sets an expiry and null lifts it again', static function () use ($drive, $sheetId, $secondEmail) {
            $grant = $drive->grant($sheetId, $secondEmail, 'reader');
            assertTrue(!$grant->inherited, 'the grant came back inherited, so this is not testing what it means to');

            try {
                $future   = new DateTimeImmutable('+3 days');
                $expiring = $drive->setExpiry($sheetId, $grant->id, $future);
                assertTrue($expiring->expiresAt !== null, 'the expiry did not set');

                // The case a plain PHP null used to lose on the way to Drive.
                $lifted = $drive->setExpiry($sheetId, $grant->id, null);
                assertTrue($lifted->expiresAt === null, 'the expiry did not lift: ' . var_export($lifted->expiresAt, true));
            } finally {
                // Swallowed on purpose: an exception raised here would replace the one the
                // assertions above are trying to report.
                try {
                    $drive->revoke($sheetId, $grant->id);
                } catch (Throwable) {
                }
            }

            return null;
        });
    }

    // ------------------------------------------------------------ limited access
    echo "\nLimited-access folders\n";

    if ($secondEmail === null) {
        skip('a grant above a limited-access folder does not reach inside it', 'needs SMOKE_SECOND_EMAIL');
    } else {
        check('a grant above a limited-access folder does not reach inside it', static function () use ($drive, $viewer, $folderId, $secondEmail, $stamp) {
            // Drive can mark a folder so that permissions from above do not reach it — "limited
            // access" in its interface, inheritedPermissionsDisabled over the API. Google does not
            // report the outer grant on the file inside; it downgrades it on the folder itself to a
            // metadata-only reader. So the way to walk past the boundary is to keep climbing, and
            // the way not to is to stop there.
            $raw   = new Drive($GLOBALS['smokeClient']);
            $outer = $drive->createFolder(PREFIX . 'outer_' . $stamp, $folderId);
            born($outer->id, 'outer folder ' . $outer->name);

            $limited = $drive->createFolder(PREFIX . 'limited_' . $stamp, $outer->id);
            born($limited->id, 'limited folder ' . $limited->name);

            $secret = $drive->createDocument(PREFIX . 'secret_' . $stamp, $limited->id, MIME_SPREADSHEET);
            born($secret->id, 'secret ' . $secret->name);

            // Writer on the OUTER folder, and nothing anywhere below it.
            $grant = $drive->grant($outer->id, $secondEmail, 'writer');

            $raw->files->update($limited->id, new Drive\DriveFile(['inheritedPermissionsDisabled' => true]), [
                'supportsAllDrives' => true,
                'fields'            => 'id,inheritedPermissionsDisabled',
            ]);

            $viewer->everything = false;
            $viewer->email      = $secondEmail;

            try {
                assertTrue(
                    $drive->canAccess($outer->id),
                    'the grant on the outer folder should still work on the outer folder'
                );
                assertTrue(
                    !$drive->canAccess($secret->id),
                    'a grant above a limited-access folder reached the document inside it'
                );
                assertTrue(
                    $drive->roleOf($secret->id) === null,
                    'roleOf reported ' . var_export($drive->roleOf($secret->id), true) . ' inside a limited folder'
                );

                $manager = new AccessDecisionManager([new DriveVoter($drive, $viewer)]);

                foreach ([DriveVoter::VIEW, DriveVoter::EDIT, DriveVoter::SHARE, DriveVoter::DELETE] as $attribute) {
                    assertTrue(
                        !$manager->decide(new NullToken(), [$attribute], $secret->id),
                        sprintf('the voter granted %s inside a limited-access folder', $attribute)
                    );
                }
            } finally {
                $viewer->everything = true;
                $viewer->email      = null;

                try {
                    $drive->revoke($outer->id, $grant->id);
                } catch (Throwable) {
                }
            }

            return null;
        });
    }

    // ------------------------------------------------------------ authorization
    echo "\nAuthorization boundary (the voter and the resolver, against real grants)\n";

    if ($secondEmail === null) {
        skip('DriveVoter separates VIEW from the mutating attributes', 'needs SMOKE_SECOND_EMAIL');
        skip('the resolver refuses an id the viewer cannot reach', 'needs SMOKE_SECOND_EMAIL');
    } elseif ($sheetId !== null) {
        // The real thing: this bundle's voter behind Symfony's own decision manager, deciding on a
        // grant Drive actually holds. A unit test answers "does the voter read a role correctly";
        // only this answers "does a reader on this drive get refused an edit".
        $decide = static function (DriveDocumentService $drive, SmokeViewer $viewer, string $attribute, mixed $subject): bool {
            $manager = new AccessDecisionManager([new DriveVoter($drive, $viewer)]);

            return $manager->decide(new NullToken(), [$attribute], $subject);
        };

        /** Runs the body as a restricted viewer, and puts the context back whatever happens. */
        $asViewer = static function (SmokeViewer $viewer, ?string $email, callable $body): mixed {
            $viewer->everything = false;
            $viewer->email      = $email;

            try {
                return $body();
            } finally {
                $viewer->everything = true;
                $viewer->email      = null;
            }
        };

        check('a reader is granted VIEW and refused every mutating attribute', static function () use ($drive, $viewer, $sheetId, $secondEmail, $decide, $asViewer) {
            $grant = $drive->grant($sheetId, $secondEmail, 'reader');

            try {
                return $asViewer($viewer, $secondEmail, static function () use ($drive, $viewer, $sheetId, $decide) {
                    assertTrue($decide($drive, $viewer, DriveVoter::VIEW, $sheetId), 'a reader was refused VIEW');

                    foreach ([DriveVoter::EDIT, DriveVoter::SHARE, DriveVoter::DELETE] as $attribute) {
                        assertTrue(
                            !$decide($drive, $viewer, $attribute, $sheetId),
                            sprintf('a reader was granted %s, so the authorization boundary is open', $attribute)
                        );
                    }

                    return null;
                });
            } finally {
                try {
                    $drive->revoke($sheetId, $grant->id);
                } catch (Throwable) {
                }
            }
        });

        check('a writer is granted all four', static function () use ($drive, $viewer, $sheetId, $secondEmail, $decide, $asViewer) {
            $grant = $drive->grant($sheetId, $secondEmail, 'writer');

            try {
                return $asViewer($viewer, $secondEmail, static function () use ($drive, $viewer, $sheetId, $decide) {
                    foreach ([DriveVoter::VIEW, DriveVoter::EDIT, DriveVoter::SHARE, DriveVoter::DELETE] as $attribute) {
                        assertTrue($decide($drive, $viewer, $attribute, $sheetId), sprintf('a writer was refused %s', $attribute));
                    }

                    return null;
                });
            } finally {
                try {
                    $drive->revoke($sheetId, $grant->id);
                } catch (Throwable) {
                }
            }
        });

        check('a viewer with no grant at all is refused everything', static function () use ($drive, $viewer, $sheetId, $secondEmail, $decide, $asViewer) {
            return $asViewer($viewer, $secondEmail, static function () use ($drive, $viewer, $sheetId, $decide) {
                foreach ([DriveVoter::VIEW, DriveVoter::EDIT, DriveVoter::SHARE, DriveVoter::DELETE] as $attribute) {
                    assertTrue(
                        !$decide($drive, $viewer, $attribute, $sheetId),
                        sprintf('an ungranted viewer was allowed %s', $attribute)
                    );
                }

                return null;
            });
        });

        check('seesEverything() passes all four without any grant', static function () use ($drive, $viewer, $sheetId, $decide) {
            foreach ([DriveVoter::VIEW, DriveVoter::EDIT, DriveVoter::SHARE, DriveVoter::DELETE] as $attribute) {
                assertTrue($decide($drive, $viewer, $attribute, $sheetId), 'the bypass did not apply to ' . $attribute);
            }

            return null;
        });

        check('the voter abstains on an empty subject instead of asking Drive about an empty id', static function () use ($drive, $viewer, $decide) {
            // An abstain with nobody else voting is a refusal, and no Drive call is made. What it
            // replaced was a 400 from Google on every such request.
            assertTrue(!$decide($drive, $viewer, DriveVoter::VIEW, ''), 'an empty subject was allowed');

            return null;
        });

        check('a DriveDocument argument resolves from the route', static function () use ($drive, $sheetId) {
            $resolver = new DriveDocumentResolver($drive);
            $request  = new Request();
            $request->attributes->set('fileId', $sheetId);

            $resolved = [];

            foreach ($resolver->resolve($request, new ArgumentMetadata('document', DriveDocument::class, false, false, null)) as $one) {
                $resolved[] = $one;
            }

            assertTrue(count($resolved) === 1, 'the resolver returned ' . count($resolved) . ' arguments');
            assertTrue($resolved[0]->id === $sheetId, 'a different document came back');

            return null;
        });

        check('the resolver refuses an id the viewer cannot reach, before the controller runs', static function () use ($drive, $viewer, $sheetId, $secondEmail, $asViewer) {
            // The whole point of resolving rather than trusting the id: the access check runs here,
            // not in a controller body someone forgot to guard.
            return $asViewer($viewer, $secondEmail, static function () use ($drive, $sheetId) {
                $resolver = new DriveDocumentResolver($drive);
                $request  = new Request();
                $request->attributes->set('fileId', $sheetId);

                try {
                    foreach ($resolver->resolve($request, new ArgumentMetadata('document', DriveDocument::class, false, false, null)) as $ignored) {
                        // Consuming the iterable is what runs the check.
                    }
                } catch (AccessDeniedException) {
                    return null;
                }

                throw new RuntimeException('an unreachable document resolved anyway');
            });
        });
    }
} catch (Throwable $e) {
    echo "\n!! the run stopped: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    Tally::$failed[] = 'run aborted — ' . $e->getMessage();
} finally {
    // ------------------------------------------------------------ cleanup
    echo "\n" . str_repeat('-', 72) . "\nCleanup\n";

    $deleted = [];
    $stuck   = [];

    // Children before the folder, so nothing is orphaned if a delete refuses.
    foreach (array_reverse(Tally::$created, true) as $id => $label) {
        if ($id === $folderId) {
            continue;
        }

        try {
            try {
                $drive->unlock($id);
            } catch (Throwable) {
                // Not locked, or not a file that can be — either way the delete decides.
            }

            $drive->deleteForever($id);
            $deleted[] = "{$label} ({$id})";
            echo "  erased {$label} ({$id})\n";
        } catch (Throwable $e) {
            $stuck[] = "{$label} ({$id}) — " . $e->getMessage();
            echo "  !! LEFT BEHIND {$label} ({$id}): " . $e->getMessage() . "\n";
        }
    }

    if ($folderId !== null) {
        try {
            $drive->deleteForever($folderId);
            $deleted[] = (Tally::$created[$folderId] ?? 'folder') . " ({$folderId})";
            echo "  erased " . (Tally::$created[$folderId] ?? 'folder') . " ({$folderId})\n";
        } catch (Throwable $e) {
            $stuck[] = "folder ({$folderId}) — " . $e->getMessage();
            echo "  !! LEFT BEHIND folder ({$folderId}): " . $e->getMessage() . "\n";
        }
    }

    foreach ($tempFiles as $path) {
        if (is_file($path)) {
            unlink($path);
            echo "  removed local {$path}\n";
        }
    }

    // ------------------------------------------------------------ the tally
    echo "\n" . str_repeat('=', 72) . "\n";
    printf(
        "passed %d   failed %d   skipped %d\n",
        count(Tally::$passed),
        count(Tally::$failed),
        count(Tally::$skipped)
    );

    if (Tally::$failed !== []) {
        echo "\nFailed:\n";
        foreach (Tally::$failed as $line) {
            echo "  - {$line}\n";
        }
    }

    if (Tally::$skipped !== []) {
        echo "\nSkipped:\n";
        foreach (Tally::$skipped as $line) {
            echo "  - {$line}\n";
        }
    }

    printf("\ncreated %d objects in the drive, erased %d\n", count(Tally::$created), count($deleted));

    if ($stuck !== []) {
        echo "\n!! STILL IN THE DRIVE — delete these by hand:\n";
        foreach ($stuck as $line) {
            echo "  - {$line}\n";
        }
    }

    $exitCode = Tally::$failed === [] && $stuck === [] ? 0 : 1;
}

// Outside the finally on purpose: an exit in there discards whatever the try was raising, which
// is exactly the mistake this script found in one of its own checks.
exit($exitCode);
