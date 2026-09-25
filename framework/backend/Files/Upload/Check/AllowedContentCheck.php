<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\Check;

use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadFailureCode;
use Hilos\Files\Upload\UploadMime;
use Hilos\Files\Upload\UploadRefusal;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Built-in check: the content of the received file is of a type the target accepts (HIL-135).
 *
 * Run only for a target that sniffs its content and lists accepted types. A declared type that
 * differs from the detected one is not a refusal by itself - browsers declare
 * application/octet-stream for everything they do not know - only a detected type outside the
 * list is.
 */
final readonly class AllowedContentCheck implements UploadCheckInterface
{
    /** Sentence of the refusal. */
    private const string MESSAGE = 'File content does not match an allowed type';

    /**
     * @param list<string> $accepted Accepted exact types and masks
     */
    public function __construct(
        private array $accepted,
    ) {
    }

    /**
     * Nothing to say: no content has arrived yet.
     *
     * @param UploadDeclaration $declaration What the browser declared
     * @return null Always
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal
    {
        return null;
    }

    /**
     * @param HilosUpload $upload Received upload with its detected type
     * @return ?UploadRefusal Refusal of content outside the accepted types or of no detected type, or null
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal
    {
        if ($this->accepted === []) {
            return null;
        }

        if ($upload->detectedMimeType !== null && UploadMime::matches($upload->detectedMimeType, $this->accepted)) {
            return null;
        }

        return new UploadRefusal(UploadFailureCode::CONTENT_MISMATCH, self::MESSAGE);
    }
}
