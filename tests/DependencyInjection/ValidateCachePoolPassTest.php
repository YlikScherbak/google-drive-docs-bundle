<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\DependencyInjection;

use Borsche\GoogleDriveDocsBundle\DependencyInjection\Compiler\ValidateCachePoolPass;
use Borsche\GoogleDriveDocsBundle\DependencyInjection\GoogleDriveDocsExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * A misconfigured cache pool must be noticed, but must not break the application.
 *
 * It says so through the compiler log alone. It used to raise a user warning as well, and an
 * application with a handler that turns warnings into exceptions had cache:clear fail on what was
 * meant to be a note — a hard failure for a typo in an optional setting.
 */
final class ValidateCachePoolPassTest extends TestCase
{
    public function testAMissingPoolIsReportedInTheCompilerLog(): void
    {
        $container = $this->containerWithPool('cache.typo');

        $warnings = $this->runPass($container);

        $log = $container->getCompiler()->getLog();

        self::assertCount(1, $log);
        self::assertStringContainsString('cache.typo', $log[0]);
        self::assertSame([], $warnings, 'a warning here breaks builds that promote them to errors');
    }

    public function testAnExistingPoolPassesQuietly(): void
    {
        $container = $this->containerWithPool('cache.app');
        $container->setDefinition('cache.app', new Definition(\stdClass::class));

        self::assertSame([], $this->runPass($container));
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testNoPoolConfiguredMeansNothingToValidate(): void
    {
        $container = $this->containerWithPool(null);

        self::assertSame([], $this->runPass($container));
        self::assertSame([], $container->getCompiler()->getLog());
    }

    private function containerWithPool(?string $pool): ContainerBuilder
    {
        $container = new ContainerBuilder();

        (new GoogleDriveDocsExtension())->load([[
            'shared_drive_id'  => 'drive',
            'permission_cache' => ['pool' => $pool, 'ttl' => 300],
        ]], $container);

        return $container;
    }

    /**
     * @return string[] the user warnings triggered by the pass
     */
    private function runPass(ContainerBuilder $container): array
    {
        $warnings = [];

        set_error_handler(
            static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            \E_USER_WARNING
        );

        try {
            (new ValidateCachePoolPass())->process($container);
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
