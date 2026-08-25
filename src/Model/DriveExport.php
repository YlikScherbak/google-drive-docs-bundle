<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

use Psr\Http\Message\StreamInterface;

/**
 * A document rendered into a downloadable format.
 *
 * The bytes are not read into memory — `stream` is the live response body, so pipe
 * it straight to the client instead of buffering it:
 *
 *     $export = $drive->export($id, DriveExport::XLSX);
 *
 *     return new StreamedResponse(
 *         static function () use ($export): void {
 *             while (!$export->stream->eof()) {
 *                 echo $export->stream->read(8192);
 *             }
 *         },
 *         200,
 *         [
 *             'Content-Type'        => $export->mimeType,
 *             'Content-Disposition' => $export->contentDisposition(),
 *         ]
 *     );
 */
final class DriveExport
{
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const ODS  = 'application/vnd.oasis.opendocument.spreadsheet';
    public const CSV  = 'text/csv';
    public const TSV  = 'text/tab-separated-values';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const ODT  = 'application/vnd.oasis.opendocument.text';
    public const RTF  = 'application/rtf';
    public const TXT  = 'text/plain';
    public const EPUB = 'application/epub+zip';

    public const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    public const ODP  = 'application/vnd.oasis.opendocument.presentation';

    public const PDF  = 'application/pdf';
    public const HTML = 'text/html';
    public const ZIP  = 'application/zip';

    /** @var array<string, string> */
    private const EXTENSIONS = [
        self::XLSX => 'xlsx',
        self::ODS  => 'ods',
        self::CSV  => 'csv',
        self::TSV  => 'tsv',
        self::DOCX => 'docx',
        self::ODT  => 'odt',
        self::RTF  => 'rtf',
        self::TXT  => 'txt',
        self::EPUB => 'epub',
        self::PPTX => 'pptx',
        self::ODP  => 'odp',
        self::PDF  => 'pdf',
        self::HTML => 'html',
        self::ZIP  => 'zip',
    ];

    public function __construct(
        /** Ready for Content-Disposition: the document name plus the format's extension. */
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly StreamInterface $stream,
    ) {
    }

    /** The file extension of a known export format, or null for anything else. */
    public static function extensionFor(string $mimeType): ?string
    {
        return self::EXTENSIONS[$mimeType] ?? null;
    }

    /**
     * The whole export as a string. Convenient for small documents and for tests;
     * for anything user-facing prefer streaming `stream` to avoid holding it all in memory.
     */
    public function contents(): string
    {
        return (string) $this->stream;
    }

    /**
     * A Content-Disposition header value with the filename escaped for both
     * legacy and UTF-8 aware clients.
     */
    public function contentDisposition(string $disposition = 'attachment'): string
    {
        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            str_replace(['"', "\r", "\n"], '', $this->filename),
            rawurlencode($this->filename)
        );
    }
}
