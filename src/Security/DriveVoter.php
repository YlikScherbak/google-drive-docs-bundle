<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Security;

use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides what the current viewer may do with a drive item, so `is_granted()` can be asked.
 *
 *     if ($this->isGranted(DriveVoter::EDIT, $document)) { ... }
 *
 * The bundle reports and does not enforce: every service method still runs as the service
 * user, and `roleOf()` only tells you what the viewer holds. This is where that fact becomes
 * a decision — in the application's own authorisation layer, where such decisions belong,
 * and where they can be replaced rather than configured.
 *
 * On purpose there is no `enforce_roles` switch inside the service. Which role a given
 * operation should require is genuinely arguable: Google itself wants `organizer` for some
 * sharing changes and `writer` for others depending on how the drive is set up. A matrix
 * baked into a library is a wrong answer nobody reviews; a voter is one you can read,
 * override and test.
 *
 * The three mutating attributes share one rule today — writer or stronger. They are separate
 * anyway so that an application can pull them apart (a stricter rule for sharing, say)
 * without rewriting the call sites that already say what they mean.
 *
 * The subject may be a DriveDocument or a plain file id.
 *
 * @extends Voter<self::VIEW|self::EDIT|self::SHARE|self::DELETE, DriveDocument|string>
 */
final class DriveVoter extends Voter
{
    /** Reachable at all: shared with the viewer, or with a folder above it. */
    public const VIEW = 'DRIVE_VIEW';

    /** Rename, move, trash, lock, write cells, change the application's own metadata. */
    public const EDIT = 'DRIVE_EDIT';

    /** Grant and revoke access. */
    public const SHARE = 'DRIVE_SHARE';

    /** Erase for good, or delete a revision. */
    public const DELETE = 'DRIVE_DELETE';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::SHARE, self::DELETE];

    /** Roles that count as write access, strongest last. */
    private const WRITERS = [
        DrivePermission::ROLE_WRITER,
        'fileOrganizer',
        'organizer',
        'owner',
    ];

    public function __construct(
        private readonly DriveDocumentService $drive,
        private readonly ViewerContextInterface $viewerContext,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true)
            && ($subject instanceof DriveDocument || (is_string($subject) && $subject !== ''));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // A viewer who bypasses filtering has no role to read, and every call would succeed
        // anyway — the service user's rights are what Google actually checks.
        if ($this->viewerContext->seesEverything()) {
            return true;
        }

        $fileId = $subject instanceof DriveDocument ? $subject->id : $subject;

        if ($attribute === self::VIEW) {
            return $this->drive->canAccess($fileId);
        }

        return in_array($this->drive->roleOf($fileId), self::WRITERS, true);
    }
}
