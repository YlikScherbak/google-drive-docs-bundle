<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Command;

use Borsche\GoogleDriveDocsBundle\Command\CheckCommand;
use Borsche\GoogleDriveDocsBundle\Model\DrivePage;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Google\Service\Drive;
use Google\Service\Drive\About;
use Google\Service\Drive\AboutStorageQuota;
use Google\Service\Drive\Drive as SharedDrive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileCapabilities;
use Google\Service\Drive\Resource\About as AboutResource;
use Google\Service\Drive\Resource\Drives;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\User;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    private AboutResource&MockObject $about;
    private Drives&MockObject $drives;
    private Files&MockObject $files;
    private DriveDocumentService&MockObject $documents;

    protected function setUp(): void
    {
        $this->about     = $this->createMock(AboutResource::class);
        $this->drives    = $this->createMock(Drives::class);
        $this->files     = $this->createMock(Files::class);
        $this->documents = $this->createMock(DriveDocumentService::class);

        $this->about->method('get')->willReturn($this->healthyAbout());
        $this->drives->method('get')->willReturn(new SharedDrive(['id' => self::DRIVE_ID, 'name' => 'Documents']));
        $this->files->method('get')->willReturn($this->rootWith(canDelete: true));
        $this->documents->method('listFolderPage')->willReturn(new DrivePage([]));
    }

    public function testAHealthySetupPassesEveryCheck(): void
    {
        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('authorised as service@example.com', $output);
        self::assertStringContainsString('"Documents" is reachable', $output);
        self::assertStringContainsString('Everything answers', $output);
    }

    public function testTheStorageQuotaIsReportedWhenGoogleGivesOne(): void
    {
        self::assertStringContainsString('2 GB of 15 GB used', $this->check()->getDisplay());
    }

    public function testAContentManagerIsToldWhyErasingWillNotWork(): void
    {
        // The most common misconfiguration: added to the drive as Content manager, which may
        // trash but not erase. Worth naming, because deleteForever() will fail much later.
        $this->files = $this->createMock(Files::class);
        $this->files->method('get')->willReturn($this->rootWith(canDelete: false));

        $output = $this->check()->getDisplay();

        self::assertStringContainsString('erase no', $output);
        self::assertStringContainsString('Manager role', $output);
    }

    public function testABadCredentialFailsOnlyItsOwnCheck(): void
    {
        // One failing check must not stop the others, or a broken first step hides the rest.
        $this->about = $this->createMock(AboutResource::class);
        $this->about->method('get')->willThrowException(
            new GoogleServiceException('Invalid Credentials', 401)
        );

        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('FAILED  Credentials', $output);
        self::assertStringContainsString('"Documents" is reachable', $output);
        self::assertStringContainsString('1 of 4 checks failed', $output);
    }

    public function testAnUnreachableDriveIsReported(): void
    {
        $this->drives = $this->createMock(Drives::class);
        $this->drives->method('get')->willThrowException(
            new GoogleServiceException('File not found: SHARED_DRIVE_ID.', 404)
        );

        $tester = $this->check();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('FAILED  Shared Drive', $tester->getDisplay());
    }

    public function testAnUnsetDriveIdIsReportedWithoutCallingGoogle(): void
    {
        $this->drives = $this->createMock(Drives::class);
        $this->drives->expects(self::never())->method('get');

        $output = $this->check(driveId: '')->getDisplay();

        self::assertStringContainsString('shared_drive_id is not set', $output);
    }

    public function testTheCommandNeverWritesToTheDrive(): void
    {
        $this->files->expects(self::never())->method('create');
        $this->files->expects(self::never())->method('update');
        $this->files->expects(self::never())->method('delete');

        $this->check();
    }

    private function check(string $driveId = self::DRIVE_ID): CommandTester
    {
        $drive              = $this->createMock(Drive::class);
        $drive->about       = $this->about;
        $drive->drives      = $this->drives;
        $drive->files       = $this->files;

        $tester = new CommandTester(new CheckCommand($drive, $this->documents, $driveId));
        $tester->execute([]);

        return $tester;
    }

    private function healthyAbout(): About
    {
        $about = new About();
        $about->setUser(new User(['displayName' => 'Integration', 'emailAddress' => 'service@example.com']));
        $about->setStorageQuota(new AboutStorageQuota([
            'usage' => (string) (2 * 1024 ** 3),
            'limit' => (string) (15 * 1024 ** 3),
        ]));

        return $about;
    }

    private function rootWith(bool $canDelete): DriveFile
    {
        $file = new DriveFile(['id' => self::DRIVE_ID]);
        $file->setCapabilities(new DriveFileCapabilities([
            'canEdit'        => true,
            'canShare'       => true,
            'canAddChildren' => true,
            'canDelete'      => $canDelete,
        ]));

        return $file;
    }
}
