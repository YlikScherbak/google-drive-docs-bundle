<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Client;

use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Google\Client;

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

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
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

        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        // "consent" guarantees a refresh token even on repeated authorisations.
        $client->setPrompt('consent');

        return $client;
    }
}
