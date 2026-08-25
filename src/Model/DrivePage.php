<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Model;

/**
 * One page of drive listing: the items Google returned, plus the token for the next page.
 *
 * Read `hasMore()`, never the item count, to decide whether to keep going. When
 * visibility filtering is active the bundle drops items Google returned but the viewer
 * may not see, so a page can be short — even empty — while later pages still hold
 * documents. That is normal, not the end of the list.
 *
 *     $page = $drive->listFolderPage($folderId, $request->query->get('page'));
 *
 *     foreach ($page as $item) { ... }
 *
 *     if ($page->hasMore()) {
 *         // link to ?page={{ page.nextPageToken }}
 *     }
 *
 * @implements \IteratorAggregate<int, DriveDocument>
 */
final class DrivePage implements \IteratorAggregate, \Countable
{
    /**
     * @param DriveDocument[] $items
     */
    public function __construct(
        public readonly array $items,
        /** Opaque Google cursor; pass it back verbatim to fetch the next page. */
        public readonly ?string $nextPageToken = null,
    ) {
    }

    /** Whether another page exists. Not the same as having items on this one. */
    public function hasMore(): bool
    {
        return $this->nextPageToken !== null;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'         => array_map(static fn (DriveDocument $d): array => $d->toArray(), $this->items),
            'nextPageToken' => $this->nextPageToken,
            'hasMore'       => $this->hasMore(),
        ];
    }
}
