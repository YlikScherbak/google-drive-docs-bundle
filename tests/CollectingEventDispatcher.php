<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Minimal PSR-14 dispatcher that records everything it receives.
 */
final class CollectingEventDispatcher implements EventDispatcherInterface
{
    /** @var object[] */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * The single event of the given class, failing loudly when the count differs.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function single(string $class): object
    {
        $matching = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));

        if (count($matching) !== 1) {
            throw new \RuntimeException(sprintf('Expected exactly one %s, got %d.', $class, count($matching)));
        }

        return $matching[0];
    }
}
