<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Controller;

use Borsche\GoogleDriveDocsBundle\Model\DriveDocument;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Turns a file id in the route into the document itself.
 *
 *     #[Route('/documents/{fileId}')]
 *     public function show(DriveDocument $document): Response
 *
 * The id is taken from the route parameter that shares the argument's name, or from `fileId`
 * or `id` when the argument is called something else. Nothing is resolved when the type is not
 * DriveDocument, so this never gets in the way of other arguments.
 *
 * Two things follow from it fetching the document rather than trusting the id.
 *
 * It costs one Drive call per resolved argument — the same call the controller would have
 * made, moved earlier, not an extra one.
 *
 * And it applies the access check: an id the viewer may not reach raises
 * `AccessDeniedException` before the controller body runs, which is the behaviour you want and
 * the reason not to take the id straight from the request.
 */
final class DriveDocumentResolver implements ValueResolverInterface
{
    /** Route parameters worth looking at when the argument's own name holds nothing. */
    private const FALLBACK_NAMES = ['fileId', 'id'];

    public function __construct(private readonly DriveDocumentService $drive)
    {
    }

    /**
     * @return iterable<DriveDocument>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== DriveDocument::class) {
            return [];
        }

        foreach ([$argument->getName(), ...self::FALLBACK_NAMES] as $name) {
            $value = $request->attributes->get($name);

            if (is_string($value) && $value !== '') {
                return [$this->drive->get($value)];
            }
        }

        // Nothing to resolve from: leave it to Symfony to complain about the argument, which
        // it does with a better message than anything invented here.
        return [];
    }
}
