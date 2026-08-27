<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Client;

use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Google\Client;
use Google\Task\Runner;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;

/**
 * Builds an authenticated Google client from an OAuth refresh token.
 *
 * Service-account keys are intentionally not used: Google blocks their creation
 * by default on new organisations (iam.managed.disableServiceAccountKeyCreation).
 * A refresh token issued for a dedicated service user works everywhere and needs
 * no organisation-policy changes.
 */
class GoogleClientFactory
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive',
    ];

    public const DEFAULT_RETRY_ATTEMPTS = 3;

    /**
     * Both default to a limit rather than to none.
     *
     * Guzzle's own default is `timeout => 0`, which means wait for ever: a TCP session that Google
     * never answers held a PHP-FPM worker until the process was killed, and the timeout error that
     * would have ended it never arrived.
     */
    public const DEFAULT_TIMEOUT         = 30.0;
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;
    public const DEFAULT_INITIAL_DELAY  = 1.0;
    public const DEFAULT_MAX_DELAY      = 60.0;

    /**
     * What is worth trying again, and what is not.
     *
     * Matched against the HTTP status first and against the first error's `reason`
     * afterwards, which is how Drive reports a quota problem behind a 403. Client
     * mistakes — 400, 401, 403 without a rate-limit reason, 404 — are deliberately
     * absent: repeating them cannot change the answer and only burns quota.
     *
     * PHP folds the numeric-string keys into integers, which is exactly how Google's
     * own default map is written and how the task runner looks them up.
     *
     * @var array<int|string, int>
     */
    private const RETRY_MAP = [
        '429'                   => Runner::TASK_RETRY_ALWAYS,
        '500'                   => Runner::TASK_RETRY_ALWAYS,
        '502'                   => Runner::TASK_RETRY_ALWAYS,
        '503'                   => Runner::TASK_RETRY_ALWAYS,
        '504'                   => Runner::TASK_RETRY_ALWAYS,
        'rateLimitExceeded'     => Runner::TASK_RETRY_ALWAYS,
        'userRateLimitExceeded' => Runner::TASK_RETRY_ALWAYS,
        'backendError'          => Runner::TASK_RETRY_ALWAYS,
        'internalError'         => Runner::TASK_RETRY_ALWAYS,
        // Curl error codes used to sit here too, inherited from the client's own default map.
        // They never fired: the task runner catches Google\Service\Exception alone, and a
        // connection that never opened arrives as a Guzzle ConnectException, which the REST layer
        // rethrows untouched because it carries no response. Connection-level failures are retried
        // by the middleware in httpClient() instead, one layer lower, where they actually appear.
    ];

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly int $retryAttempts = self::DEFAULT_RETRY_ATTEMPTS,
        private readonly float $retryInitialDelay = self::DEFAULT_INITIAL_DELAY,
        private readonly float $retryMaxDelay = self::DEFAULT_MAX_DELAY,
        /** Seconds for a whole request; 0 leaves Guzzle's own default, which is no limit at all. */
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        /** Seconds to get the connection open. */
        private readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
    }

    /**
     * Ready-to-use client with a fresh access token.
     */
    public function create(): Client
    {
        $client = $this->baseClient();

        if ($this->refreshToken === '') {
            throw new NotConfiguredException(
                'Google OAuth is not authorised: empty refresh token. '
                . 'Run "bin/console google-drive-docs:authorize" to obtain one.'
            );
        }

        $token = $client->fetchAccessTokenWithRefreshToken($this->refreshToken);

        if (isset($token['error'])) {
            throw new NotConfiguredException(sprintf(
                'Could not refresh the Google access token: %s',
                $token['error_description'] ?? $token['error']
            ));
        }

        return $client;
    }

    /**
     * Client without a token — shared by create() and the authorization flow.
     */
    public function baseClient(): Client
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new NotConfiguredException(
                'Google OAuth client_id/client_secret are not configured.'
            );
        }

        $client = new Client($this->retryConfig());
        $client->setHttpClient($this->httpClient((string) $client->getConfig('base_path')));
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        // "consent" guarantees a refresh token even on repeated authorisations.
        $client->setPrompt('consent');

        return $client;
    }

    /**
     * The HTTP client the Google client will use, with limits and one retry the SDK does not do.
     *
     * Two things are added and the SDK's own two are kept. `base_uri` and `http_errors => false`
     * are what `Google\Client` sets when it builds its own, and the second is load-bearing: the
     * REST layer reads the status off the response rather than catching an exception.
     *
     * The retry is for connection-level failures only — DNS, connect, TLS, an empty reply. Those
     * never reach the task runner, so before this they were not retried at all despite a map that
     * suggested otherwise. Responses are left alone here: a 429 or a 503 comes back as a response
     * (http_errors is off), so the decider sees no exception and the task runner handles it. The
     * two ladders cannot stack, because they trigger on disjoint outcomes.
     */
    private function httpClient(string $baseUri): GuzzleClient
    {
        $stack = HandlerStack::create();

        if ($this->retryAttempts > 0) {
            $stack->push(RetryOnConnectionFailure::middleware(
                $this->retryAttempts,
                $this->retryInitialDelay,
                $this->retryMaxDelay
            ));
        }

        $options = [
            'base_uri'    => $baseUri,
            'http_errors' => false,
            'handler'     => $stack,
        ];

        if ($this->timeout > 0) {
            $options['timeout'] = $this->timeout;
        }

        if ($this->connectTimeout > 0) {
            $options['connect_timeout'] = $this->connectTimeout;
        }

        return new GuzzleClient($options);
    }

    /**
     * Exponential backoff for the failures that are worth another try.
     *
     * Google throttles per user and per project, and a Shared Drive listing can trip
     * that on a busy page. Without this a single 429 reaches the end user as an error;
     * with it the client waits and tries again.
     *
     * @return array<string, mixed>
     */
    private function retryConfig(): array
    {
        if ($this->retryAttempts <= 0) {
            return [];
        }

        return [
            'retry' => [
                'retries'       => $this->retryAttempts,
                'initial_delay' => $this->retryInitialDelay,
                'max_delay'     => $this->retryMaxDelay,
            ],
            'retry_map' => self::RETRY_MAP,
        ];
    }
}
