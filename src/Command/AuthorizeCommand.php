<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Command;

use Borsche\GoogleDriveDocsBundle\Client\GoogleClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off helper that exchanges an OAuth consent for a long-lived refresh token.
 *
 * Step 1 (no argument): prints the consent URL.
 * Step 2 (with the code): prints the refresh token to put into your configuration.
 */
#[AsCommand(
    name: 'google-drive-docs:authorize',
    description: 'Obtain the OAuth refresh token used by the bundle.',
)]
class AuthorizeCommand extends Command
{
    private const REDIRECT_URI = 'http://localhost';

    public function __construct(private readonly GoogleClientFactory $factory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::OPTIONAL, 'Authorization code taken from the redirect URL (step 2).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $client = $this->factory->baseClient();
        $client->setRedirectUri(self::REDIRECT_URI);

        $code = $input->getArgument('code');

        if (!$code) {
            $io->title('Step 1 — grant access');
            $io->listing([
                'Sign in to the browser as the service user that owns the documents.',
                'Open the URL below and approve the requested scopes.',
                'You will be redirected to ' . self::REDIRECT_URI . '/?code=... (the page itself will not load — that is fine).',
                'Copy the "code" query parameter and run this command again with it.',
            ]);
            $io->writeln($client->createAuthUrl());
            $io->newLine();
            $io->comment('Then: bin/console google-drive-docs:authorize "<code>"');

            return Command::SUCCESS;
        }

        $token = $client->fetchAccessTokenWithAuthCode(urldecode((string) $code));

        if (isset($token['error'])) {
            $io->error(sprintf('Code exchange failed: %s', $token['error_description'] ?? $token['error']));

            return Command::FAILURE;
        }

        if (empty($token['refresh_token'])) {
            $io->error(
                'Google did not return a refresh token. Revoke the app at '
                . 'https://myaccount.google.com/permissions and try again.'
            );

            return Command::FAILURE;
        }

        $io->success('Refresh token obtained. Put it into your configuration:');
        $io->writeln('GOOGLE_DRIVE_DOCS_REFRESH_TOKEN=' . $token['refresh_token']);

        return Command::SUCCESS;
    }
}
