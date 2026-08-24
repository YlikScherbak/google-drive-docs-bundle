<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests;

use Borsche\GoogleDriveDocsBundle\Contract\ViewerContextInterface;

final class FakeViewerContext implements ViewerContextInterface
{
    /**
     * @param string[] $groups
     */
    public function __construct(
        private readonly ?string $email,
        private readonly bool $seesEverything = false,
        private readonly array $groups = [],
    ) {
    }

    public function getViewerEmail(): ?string
    {
        return $this->email;
    }

    public function seesEverything(): bool
    {
        return $this->seesEverything;
    }

    /**
     * @return string[]
     */
    public function getViewerGroups(): array
    {
        return $this->groups;
    }
}
