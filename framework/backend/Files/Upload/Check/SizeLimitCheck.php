<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\Check;

use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadRefusal;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Built-in check: the declared size is not empty and not above the target's limit (HIL-135).
 *
 * Judged on the declaration alone: the agent never accepts a byte past the declared size, so a
 * received file cannot outgrow what this check let through.
 */
final readonly class SizeLimitCheck implements UploadCheckInterface
{
    /** Code of the refusal of an empty file. */
    public const string CODE_EMPTY = 'empty_file';

    /** Code of the refusal of a file above the limit. */
    public const string CODE_TOO_LARGE = 'size_limit';

    /** Sentence of the refusal of an empty file. */
    private const string MESSAGE_EMPTY = 'File is empty';

    /** Sentence of the refusal of a file above the limit, as the chat composer shows it. */
    private const string MESSAGE_TOO_LARGE = 'File is larger than the allowed size';

    /**
     * @param int $maxBytes Largest size the target accepts, in bytes
     */
    public function __construct(
        private int $maxBytes,
    ) {
    }

    /**
     * @param UploadDeclaration $declaration What the browser declared
     * @return ?UploadRefusal Refusal of an empty or oversized file, or null
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal
    {
        if ($declaration->size === 0) {
            return new UploadRefusal(self::CODE_EMPTY, self::MESSAGE_EMPTY);
        }

        if ($declaration->size > $this->maxBytes) {
            return new UploadRefusal(self::CODE_TOO_LARGE, self::MESSAGE_TOO_LARGE);
        }

        return null;
    }

    /**
     * Nothing to say: the received size is the declared one.
     *
     * @param HilosUpload $upload Received upload
     * @return null Always
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal
    {
        return null;
    }
}
