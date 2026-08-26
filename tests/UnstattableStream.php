<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests;

/**
 * A stream whose stat says nothing useful, the way a network mount or a wrapper can.
 *
 * `filesize()` on it answers 0 while the bytes are all there, which is the case the upload
 * path has to survive: the stat is not an authority on how big the file is, the stream is.
 * Seeking works, because that is what is left to measure with.
 */
final class UnstattableStream
{
    public const SCHEME = 'unstattable';

    /** Regular file, readable by anyone — enough for is_file() and is_readable(). */
    private const STAT = ['size' => 0, 'mode' => 0100666];

    public static string $contents = '';

    /** @var resource|null set by PHP for stream contexts */
    public $context;

    private int $offset = 0;

    /** Registers the wrapper, arms it with the given bytes and returns the path to import. */
    public static function of(string $contents): string
    {
        self::$contents = $contents;

        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        return self::SCHEME . '://big.csv';
    }

    public static function forget(): void
    {
        self::$contents = '';

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$contents, $this->offset, $count);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(self::$contents);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->offset + $offset,
            SEEK_END => strlen(self::$contents) + $offset,
            default  => -1,
        };

        if ($target < 0) {
            return false;
        }

        $this->offset = $target;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return self::STAT;
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return self::STAT;
    }

    public function stream_close(): void
    {
    }
}
