<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Controller;

use Borsche\GoogleDriveDocsBundle\Controller\DriveDocumentResolver;
use Borsche\GoogleDriveDocsBundle\Exception\AccessDeniedException;
use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class DriveDocumentResolverTest extends TestCase
{
    private DriveDocumentService&MockObject $drive;

    protected function setUp(): void
    {
        $this->drive = $this->createMock(DriveDocumentService::class);
    }

    public function testTheArgumentsOwnNameIsTriedFirst(): void
    {
        $this->drive->method('get')->with('doc-1')->willReturn($this->document());

        $resolved = $this->resolve(['document' => 'doc-1'], 'document');

        self::assertCount(1, $resolved);
        self::assertSame('doc-1', $resolved[0]->id);
    }

    /**
     * @dataProvider fallbackNames
     */
    public function testACommonRouteParameterIsUsedWhenTheNamesDiffer(string $parameter): void
    {
        $this->drive->method('get')->with('doc-7')->willReturn($this->document('doc-7'));

        $resolved = $this->resolve([$parameter => 'doc-7'], 'report');

        self::assertSame('doc-7', $resolved[0]->id);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fallbackNames(): iterable
    {
        yield 'fileId' => ['fileId'];
        yield 'id'     => ['id'];
    }

    public function testAnotherTypeIsLeftAlone(): void
    {
        $this->drive->expects(self::never())->method('get');

        self::assertSame([], $this->resolve(['document' => 'doc-1'], 'document', \stdClass::class));
    }

    public function testNothingToResolveFromIsLeftToSymfony(): void
    {
        // Returning nothing lets the framework complain about the argument, which it does
        // better than anything invented here.
        $this->drive->expects(self::never())->method('get');

        self::assertSame([], $this->resolve([], 'document'));
    }

    public function testAnEmptyParameterIsNotAnId(): void
    {
        $this->drive->expects(self::never())->method('get');

        self::assertSame([], $this->resolve(['document' => ''], 'document'));
    }

    public function testAnUnreachableItemIsRefusedBeforeTheControllerRuns(): void
    {
        // The reason to fetch rather than trust the id: the access check happens here.
        $this->drive->method('get')->willThrowException(new AccessDeniedException('nope'));

        $this->expectException(AccessDeniedException::class);

        $this->resolve(['document' => 'doc-1'], 'document');
    }

    /**
     * @param array<string, mixed> $attributes
     * @return DriveDocument[]
     */
    private function resolve(array $attributes, string $name, string $type = DriveDocument::class): array
    {
        $request = new Request();
        $request->attributes->add($attributes);

        $argument = new ArgumentMetadata($name, $type, false, false, null);

        $resolved = [];

        // Collected by hand rather than with iterator_to_array(): resolve() returns an
        // iterable, which is an array here, and on PHP 8.1 that function still insists on a
        // Traversable.
        foreach ((new DriveDocumentResolver($this->drive))->resolve($request, $argument) as $document) {
            $resolved[] = $document;
        }

        return $resolved;
    }

    private function document(string $id = 'doc-1'): DriveDocument
    {
        return new DriveDocument($id, 'Q3 report', null, null, null, DriveDocument::TYPE_DOCUMENT);
    }
}
