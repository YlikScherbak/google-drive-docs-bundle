<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/**
 * Retries the failures that never reach Google's own task runner.
 *
 * The runner catches `Google\Service\Exception` and nothing else, and a connection that never
 * opened — DNS, connect, TLS handshake, an empty reply — arrives as a Guzzle `ConnectException`
 * that the REST layer rethrows untouched because it carries no response. So the curl error codes
 * that used to sit in the retry map were unreachable, and the one class of failure most worth
 * another attempt was the one not retried at all.
 *
 * Deliberately narrow. A response is never retried here: with `http_errors` off a 429 or a 503
 * comes back as a response rather than an exception, so the decider sees no exception and leaves
 * it to the task runner, which knows which reasons are worth repeating. The two ladders trigger on
 * disjoint outcomes and cannot stack.
 *
 * Requests are not checked for idempotence, for the same reason the task runner does not: a
 * connection that was never established cannot have been acted on.
 */
final class RetryOnConnectionFailure
{
    /**
     * @param int   $attempts     extra tries after the first failure; 0 disables retrying
     * @param float $initialDelay seconds before the first retry, doubling on each further one
     * @param float $maxDelay     upper bound in seconds for a single wait
     *
     * @return callable middleware for a Guzzle handler stack
     */
    public static function middleware(int $attempts, float $initialDelay, float $maxDelay): callable
    {
        return Middleware::retry(
            static fn (int $retries, RequestInterface $request, $response, ?\Throwable $exception): bool
                => $exception instanceof ConnectException && $retries < $attempts,
            static fn (int $retries): int
                => (int) round(1000 * min($maxDelay, $initialDelay * (2 ** $retries)))
        );
    }
}
