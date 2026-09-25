<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * One check's "no" to an upload: a stable code and the sentence a person reads (HIL-135).
 *
 * The code travels only when the upload fails after its start; a refusal of the declaration
 * reaches the browser as the action's error, which carries the sentence alone.
 */
final readonly class UploadRefusal
{
    /**
     * @param string $code Stable error code the client may branch on
     * @param string $message Sentence shown to the person
     */
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }
}
