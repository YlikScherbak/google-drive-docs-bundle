<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Model;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use PHPUnit\Framework\TestCase;

final class DriveDocumentTest extends TestCase
{
    public function testFolderIsRecognised(): void
    {
        $folder = new DriveDocument('id', 'Reports', 'application/vnd.google-apps.folder', null, null, DriveDocument::TYPE_FOLDER);

        self::assertTrue($folder->isFolder());
    }

    public function testDocumentIsNotAFolder(): void
    {
        $doc = new DriveDocument('id', 'Prices', 'application/vnd.google-apps.spreadsheet', null, null, DriveDocument::TYPE_DOCUMENT);

        self::assertFalse($doc->isFolder());
    }

    public function testToArrayExposesEveryField(): void
    {
        $doc = new DriveDocument(
            'file-1',
            'Prices',
            'application/vnd.google-apps.spreadsheet',
            'https://docs.google.com/spreadsheets/d/file-1/edit',
            '2026-01-01T00:00:00.000Z',
            DriveDocument::TYPE_DOCUMENT
        );

        self::assertSame([
            'id'           => 'file-1',
            'name'         => 'Prices',
            'mimeType'     => 'application/vnd.google-apps.spreadsheet',
            'webViewLink'  => 'https://docs.google.com/spreadsheets/d/file-1/edit',
            'modifiedTime' => '2026-01-01T00:00:00.000Z',
            'type'         => 'document',
        ], $doc->toArray());
    }
}
