<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Model;

use Borsche\GoogleDriveDocsBundle\Model\DrivePermission;
use PHPUnit\Framework\TestCase;

final class DrivePermissionTest extends TestCase
{
    public function testDefaultsToADirectPermission(): void
    {
        $permission = new DrivePermission('p1', 'user@example.com', 'writer', 'user', 'User');

        self::assertFalse($permission->inherited);
        self::assertNull($permission->inheritedFrom);
    }

    public function testToArrayExposesInheritance(): void
    {
        $permission = new DrivePermission('p1', 'user@example.com', 'reader', 'user', 'User', true, 'folder-1');

        self::assertSame([
            'id'            => 'p1',
            'emailAddress'  => 'user@example.com',
            'role'          => 'reader',
            'type'          => 'user',
            'displayName'   => 'User',
            'inherited'     => true,
            'inheritedFrom' => 'folder-1',
        ], $permission->toArray());
    }
}
