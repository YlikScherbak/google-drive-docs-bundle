<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/**
 * Retries the failures that never reach Google's own task runner — the ones that are safe to repeat.
 *
 * The runner catches `Google\Service\Exception` and nothing else, and a connection-level failure
 * arrives as a Guzzle `ConnectException` that the REST layer rethrows untouched because it carries
 * no response. So the curl error codes that used to sit in the retry map were unreachable, and the
 * one class of failure most worth another attempt was the one not retried at all.
 *
 * **`ConnectException` does not mean the request was never sent.** That reading is what made the
 * first version of this class unsafe. Guzzle maps exactly five curl errors to it, and two of them
 * say nothing about whether Google acted on the request:
 *
 * | curl | meaning                | reached Google? |
 * | ---- | ---------------------- | --------------- |
 * | 6    | could not resolve host | no              |
 * | 7    | could not connect      | no              |
 * | 35   | TLS handshake failed   | no              |
 * | 28   | operation timed out    | **maybe**       |
 * | 52   | server sent nothing    | **maybe**       |
 *
 * A timeout can happen after Drive has appended the row and before the response comes back, so
 * repeating that request writes it twice. The first three cannot have been acted on, and are
 * retried whatever the method is. For the last two — and for a handler that reports no error code
 * at all, which the context is documented as allowing — only the methods with no side effects are
 * repeated. A `POST` that may have been applied is handed to the caller as a failure instead,
 * because a duplicated row is worse than an error someone can see.
 *
 * A response is never retried here: with `http_errors` off a 429 or a 503 comes back as a response
 * rather than an exception, so the decider sees no exception and leaves it to the task runner, which
 * knows which reasons are worth repeating. The two ladders trigger on disjoint outcomes.
 */
final class RetryOnConnectionFailure
{
    /** Failures that happen before the request can reach Google, so repeating one is free. */
    private const NEVER_SENT = [
        \CURLE_COULDNT_RESOLVE_HOST,  // 6
        \CURLE_COULDNT_CONNECT,       // 7
        \CURLE_SSL_CONNECT_ERROR,     // 35
    ];

    /**
     * Methods with no side effects, which may be repeated after an ambiguous failure.
     *
     * PUT and DELETE are idempotent by the specification and could arguably join them, but only
     * arguably: what this bundle sends as PUT is a resumable upload chunk, and the value of getting
     * one more attempt there does not outweigh reasoning about a half-written upload. PATCH is not
     * even arguable — it is what Drive uses for every update, and a partial update applied twice is
     * not the same as once wherever a value depends on the current one.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

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
                => $exception instanceof ConnectException
                    && $retries < $attempts
                    && self::safeToRepeat($request, $exception),
            static fn (int $retries): int
                => (int) round(1000 * min($maxDelay, $initialDelay * (2 ** $retries)))
        );
    }

    /**
     * Whether this request can be sent again without risking a second effect.
     *
     * An absent error code is treated as ambiguous rather than as safe: the handler context is
     * documented as possibly empty, and guessing in the permissive direction here means guessing
     * that a write did not happen.
     */
    private static function safeToRepeat(RequestInterface $request, ConnectException $exception): bool
    {
        $errno = $exception->getHandlerContext()['errno'] ?? null;

        if (is_int($errno) && in_array($errno, self::NEVER_SENT, true)) {
            return true;
        }

        return in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true);
    }
}
