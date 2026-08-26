<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Security;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Security\DriveVoter;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DriveVoterTest extends TestCase
{
    private DriveDocumentService&MockObject $drive;
    private TokenInterface&MockObject $token;

    protected function setUp(): void
    {
        $this->drive = $this->createMock(DriveDocumentService::class);
        $this->token = $this->createMock(TokenInterface::class);
    }

    public function testViewFollowsWhetherTheItemIsReachable(): void
    {
        $this->drive->method('canAccess')->willReturn(true);
        $this->drive->expects(self::never())->method('roleOf');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(DriveVoter::VIEW, $this->document())
        );
    }

    public function testViewIsDeniedForAnItemOutOfReach(): void
    {
        $this->drive->method('canAccess')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(DriveVoter::VIEW, $this->document())
        );
    }

    /**
     * @dataProvider mutatingAttributes
     */
    public function testAReaderMaySeeButNotChange(string $attribute): void
    {
        $this->drive->method('canAccess')->willReturn(true);
        $this->drive->method('roleOf')->willReturn('reader');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($attribute, $this->document()));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(DriveVoter::VIEW, $this->document()));
    }

    /**
     * @dataProvider mutatingAttributes
     */
    public function testAWriterMayChange(string $attribute): void
    {
        $this->drive->method('roleOf')->willReturn('writer');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($attribute, $this->document()));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function mutatingAttributes(): iterable
    {
        yield 'edit'   => [DriveVoter::EDIT];
        yield 'share'  => [DriveVoter::SHARE];
        yield 'delete' => [DriveVoter::DELETE];
    }

    public function testACommenterCountsAsAReader(): void
    {
        $this->drive->method('roleOf')->willReturn('commenter');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(DriveVoter::EDIT, $this->document()));
    }

    /**
     * @dataProvider strongerRoles
     */
    public function testARoleAboveWriterAlsoCounts(string $role): void
    {
        // These come from the drive's own membership and the bundle cannot hand them out, but
        // someone holding one is certainly allowed to edit.
        $this->drive->method('roleOf')->willReturn($role);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(DriveVoter::EDIT, $this->document()));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function strongerRoles(): iterable
    {
        yield 'fileOrganizer' => ['fileOrganizer'];
        yield 'organizer'     => ['organizer'];
        yield 'owner'         => ['owner'];
    }

    public function testNoRoleAtAllIsADenial(): void
    {
        $this->drive->method('roleOf')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(DriveVoter::EDIT, $this->document()));
    }

    public function testAViewerWhoSeesEverythingIsNotAskedAboutRoles(): void
    {
        // roleOf() answers null for them by design, so asking would deny an administrator.
        $this->drive->expects(self::never())->method('roleOf');
        $this->drive->expects(self::never())->method('canAccess');

        $voter = new DriveVoter($this->drive, new FakeViewerContext('admin@example.com', true));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token, $this->document(), [DriveVoter::DELETE])
        );
    }

    public function testAPlainFileIdWorksAsTheSubject(): void
    {
        $this->drive->method('roleOf')->willReturn('writer');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(DriveVoter::EDIT, 'doc-1'));
    }

    public function testAnEmptyIdIsAbstainedOnRatherThanAskedAboutAtGoogle(): void
    {
        // "" is not an id; asking Google would earn a 400 instead of a decision.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(DriveVoter::VIEW, ''));
    }

    public function testSomethingElseEntirelyIsAbstainedOn(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote(DriveVoter::EDIT, new \stdClass())
        );
    }

    public function testAnUnrelatedAttributeIsAbstainedOn(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote('ROLE_ADMIN', $this->document())
        );
    }

    private function vote(string $attribute, mixed $subject): int
    {
        $voter = new DriveVoter($this->drive, new FakeViewerContext('viewer@example.com'));

        return $voter->vote($this->token, $subject, [$attribute]);
    }

    private function document(): DriveDocument
    {
        return new DriveDocument('doc-1', 'Q3 report', null, null, null, DriveDocument::TYPE_DOCUMENT);
    }
}
