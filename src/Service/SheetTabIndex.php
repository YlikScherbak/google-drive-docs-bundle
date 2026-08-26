<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Service;

use Google\Service\Sheets;

/**
 * Tab title to the numeric sheet id Google's formatting calls actually take.
 *
 * Both `SpreadsheetService` and `SheetFormatter` need this map and neither owns the other,
 * so it lives here rather than in each of them. That is not tidiness for its own sake: the
 * two copies it replaces had to be given the same null guard separately once already, and a
 * fix applied to one of two identical blocks is the kind that looks done and is not.
 *
 * Titles are the user's to change and the id is what survives a rename, which is why nothing
 * here caches: a map read a minute ago may already name a tab that no longer exists.
 */
final class SheetTabIndex
{
    /**
     * @return array<string, int> in the order the spreadsheet holds them, so the first key is
     *                           the tab a range without a name refers to
     */
    public static function of(Sheets $sheets, string $fileId): array
    {
        $spreadsheet = $sheets->spreadsheets->get($fileId, [
            'fields' => 'sheets.properties(title,sheetId)',
        ]);

        $ids = [];

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();

            if ($properties === null || $properties->getTitle() === null || $properties->getSheetId() === null) {
                continue;
            }

            $ids[$properties->getTitle()] = $properties->getSheetId();
        }

        return $ids;
    }
}
