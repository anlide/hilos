<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * Error codes an upload fails with after its start, as they travel on the wire (HIL-135).
 *
 * Only the failures the uploads agent itself concludes are named here. A refusal of the
 * declaration is the action's error and carries no code; a check a project adds through
 * {@see AbstractUploadTarget::extraChecks()} brings its own code with its refusal.
 */
final class UploadFailureCode
{
    /** More bytes arrived than the declaration named. */
    public const string SIZE_OVERFLOW = 'size_overflow';

    /** A chunk could not be appended to the temporary file. */
    public const string WRITE_ERROR = 'write_error';

    /** The content of the whole file is not of a type the target accepts. */
    public const string CONTENT_MISMATCH = 'content_mismatch';

    /** The whole file could not be read back to tell its type. */
    public const string STORAGE_ERROR = 'storage_error';
}
