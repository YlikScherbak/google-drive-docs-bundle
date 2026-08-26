<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Command;

use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Google\Service\Drive;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answers the question nothing else here answers: is this actually configured right?
 *
 * Every check is one call and reports on its own, so a half-working setup says which half.
 * The command is read-only — it never writes to the drive.
 */
#[AsCommand(
    name: 'google-drive-docs:check',
    description: 'Verify the Google credentials, the Shared Drive and the service user\'s rights.',
)]
class CheckCommand extends Command
{
    public function __construct(
        private readonly Drive $drive,
        private readonly DriveDocumentService $documents,
        private readonly string $sharedDriveId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Google Drive Docs');

        $failed = 0;

        $failed += $this->report($io, 'Credentials', function () use ($io): string {
            $about = $this->drive->about->get(['fields' => 'user(displayName,emailAddress),storageQuota']);
            $user  = $about->getUser();
            $quota = $about->getStorageQuota();

            if ($quota !== null && $quota->getLimit() !== null) {
                $io->text(sprintf(
                    '  storage: %s of %s used',
                    $this->bytes((int) $quota->getUsage()),
                    $this->bytes((int) $quota->getLimit())
                ));
            }

            return sprintf(
                'authorised as %s',
                $user?->getEmailAddress() ?? $user?->getDisplayName() ?? 'an unnamed account'
            );
        });

        $failed += $this->report($io, 'Shared Drive', function (): string {
            if ($this->sharedDriveId === '') {
                throw new \RuntimeException('shared_drive_id is not set.');
            }

            $drive = $this->drive->drives->get($this->sharedDriveId, ['fields' => 'id,name']);

            return sprintf('"%s" is reachable', $drive->getName() ?? $this->sharedDriveId);
        });

        $failed += $this->report($io, 'Rights on the drive', function () use ($io): string {
            // The root itself carries the capabilities the service user has drive-wide, which
            // is what decides whether deleteForever() and sharing will work at all.
            $root = $this->drive->files->get($this->sharedDriveId, [
                'supportsAllDrives' => true,
                'fields'            => 'capabilities(canDelete,canShare,canAddChildren,canEdit)',
            ]);

            $can = $root->getCapabilities();

            if ($can === null) {
                throw new \RuntimeException('Google did not report what this account may do here.');
            }

            if (!$can->getCanDelete()) {
                $io->text('  note: deleteForever() needs the Manager role; "Content manager" may only trash');
            }

            return sprintf(
                'edit %s, share %s, create %s, erase %s',
                $this->tick($can->getCanEdit()),
                $this->tick($can->getCanShare()),
                $this->tick($can->getCanAddChildren()),
                $this->tick($can->getCanDelete())
            );
        });

        $failed += $this->report($io, 'Listing', function (): string {
            $page = $this->documents->listFolderPage(null, null, 1);

            return $page->hasMore() || $page->count() > 0
                ? 'the root answers and holds items'
                : 'the root answers, and is empty';
        });

        if ($failed > 0) {
            $io->error(sprintf('%d of 4 checks failed.', $failed));

            return Command::FAILURE;
        }

        $io->success('Everything answers.');

        return Command::SUCCESS;
    }

    /**
     * Runs one check, printing what it found or why it could not. Returns 1 on failure so the
     * caller can total them — a check failing must not stop the ones after it, or a broken
     * first step would hide everything behind it.
     *
     * @param callable(): string $check
     */
    private function report(SymfonyStyle $io, string $label, callable $check): int
    {
        try {
            $io->writeln(sprintf('<info>OK</info>      %s: %s', $label, $check()));

            return 0;
        } catch (\Throwable $e) {
            $io->writeln(sprintf('<error>FAILED</error>  %s: %s', $label, $e->getMessage()));

            return 1;
        }
    }

    private function tick(?bool $allowed): string
    {
        return $allowed === true ? 'yes' : 'no';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit  = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes = intdiv($bytes, 1024);
            ++$unit;
        }

        return $bytes . ' ' . $units[$unit];
    }
}
