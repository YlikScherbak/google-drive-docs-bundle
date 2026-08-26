<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Exception\NotConfiguredException;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\CollectingEventDispatcher;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DriveDocumentServiceMultiDriveTest extends TestCase
{
    private const DRIVE_ID = 'FIRST_DRIVE';
    private const OTHER_ID = 'SECOND_DRIVE';

    private Files&MockObject $files;
    private Permissions&MockObject $permissions;

    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->files       = $this->createMock(Files::class);
        $this->permissions = $this->createMock(Permissions::class);
        $this->requests    = [];

        $this->files->method('listFiles')->willReturnCallback(
            function (array $params): FileList {
                $this->requests[] = $params;

                return new FileList();
            }
        );
    }

    public function testForDriveScopesEverythingToTheOtherDrive(): void
    {
        $this->service()->forDrive(self::OTHER_ID)->listFolder();

        self::assertSame(self::OTHER_ID, $this->requests[0]['driveId']);
        self::assertStringContainsString("'" . self::OTHER_ID . "' in parents", $this->requests[0]['q']);
    }

    public function testTheOriginalIsLeftAlone(): void
    {
        $service = $this->service();
        $service->forDrive(self::OTHER_ID)->listFolder();
        $service->listFolder();

        self::assertSame(self::OTHER_ID, $this->requests[0]['driveId']);
        self::assertSame(self::DRIVE_ID, $this->requests[1]['driveId']);
    }

    public function testAskingForTheSameDriveHandsBackTheSameInstance(): void
    {
        $service = $this->service();

        self::assertSame($service, $service->forDrive(self::DRIVE_ID));
    }

    public function testEverySettingTravelsToTheNewInstance(): void
    {
        // The clone is built by hand, so a constructor argument added later has to be added
        // here too. This is the test that notices when one is not.
        $pool    = new ArrayAdapter();
        $service = new DriveDocumentService(
            $this->drive(),
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.document'],
            true,
            new CollectingEventDispatcher(),
            $pool,
            60,
            1024,
            DriveDocumentService::CHUNK_GRANULARITY
        );

        $other = $service->forDrive(self::OTHER_ID);

        // Observable through behaviour rather than reflection: the MIME types shape the query,
        // and the upload cap and chunk size are validated the same way.
        $other->listFolder();

        self::assertStringContainsString('vnd.google-apps.document', $this->requests[0]['q']);
        self::assertStringNotContainsString('spreadsheet', $this->requests[0]['q']);

        $reflection = new \ReflectionClass(DriveDocumentService::class);

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $property = $reflection->getProperty($parameter->getName());

            if ($parameter->getName() === 'sharedDriveId') {
                self::assertSame(self::OTHER_ID, $property->getValue($other));

                continue;
            }

            self::assertSame(
                $property->getValue($service),
                $property->getValue($other),
                sprintf('forDrive() dropped "%s"', $parameter->getName())
            );
        }
    }

    public function testAMalformedDriveIdIsRefusedWhereItIsGiven(): void
    {
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/not a Google Drive id/');

        $this->service()->forDrive('not a drive id');
    }

    public function testAnEmptyDriveIdIsRefused(): void
    {
        $this->expectException(NotConfiguredException::class);

        $this->service()->forDrive('');
    }

    private function drive(): Drive
    {
        $drive              = $this->createMock(Drive::class);
        $drive->files       = $this->files;
        $drive->permissions = $this->permissions;

        return $drive;
    }

    private function service(): DriveDocumentService
    {
        return new DriveDocumentService(
            $this->drive(),
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet'],
            false
        );
    }
}
