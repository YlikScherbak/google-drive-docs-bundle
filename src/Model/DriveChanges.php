<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * Everything that happened since a token, and the token to ask from next time.
 *
 * Store `nextToken` wherever your application keeps state — the bundle has nowhere to put it
 * — and hand it back on the next poll. Asking with a token you already used replays those
 * changes; asking with a token you never received loses whatever happened in between.
 *
 * @implements \IteratorAggregate<int, DriveChange>
 */
final class DriveChanges implements \IteratorAggregate, \Countable
{
    /**
     * @param DriveChange[] $changes
     */
    public function __construct(
        public readonly array $changes,
        public readonly string $nextToken,
    ) {
    }

    /** Whether anything happened at all — the usual answer on a quiet poll is no. */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->changes);
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'changes'   => array_map(static fn (DriveChange $c): array => $c->toArray(), $this->changes),
            'nextToken' => $this->nextToken,
        ];
    }
}
