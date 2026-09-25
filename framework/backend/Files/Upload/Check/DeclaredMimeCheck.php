<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\Check;

use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadMime;
use Hilos\Files\Upload\UploadRefusal;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Built-in check: the declared type is a type at all, and one the target accepts (HIL-135).
 *
 * What the browser declared is only a claim; a target that must trust the type also sniffs the
 * content ({@see AllowedContentCheck}).
 */
final readonly class DeclaredMimeCheck implements UploadCheckInterface
{
    /** Code of the refusal. */
    public const string CODE = 'mime_not_allowed';

    /** Sentence of the refusal. */
    private const string MESSAGE = 'This file type is not allowed';

    /**
     * @param list<string> $accepted Accepted exact types and masks; empty accepts any well-formed type
     */
    public function __construct(
        private array $accepted,
    ) {
    }

    /**
     * @param UploadDeclaration $declaration What the browser declared, with a normalized type
     * @return ?UploadRefusal Refusal of a malformed or unaccepted type, or null
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal
    {
        if (!UploadMime::isWellFormed($declaration->mimeType)
            || ($this->accepted !== [] && !UploadMime::matches($declaration->mimeType, $this->accepted))) {
            return new UploadRefusal(self::CODE, self::MESSAGE);
        }

        return null;
    }

    /**
     * Nothing to say: the declared type does not change on arrival.
     *
     * @param HilosUpload $upload Received upload
     * @return null Always
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal
    {
        return null;
    }
}
