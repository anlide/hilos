<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * Where one upload stands (HIL-135).
 *
 * READY and UPLOADING hold a file that is still arriving; COMPLETE holds a whole file waiting
 * for its consumer; FAILED holds none - its temporary file is deleted the moment it fails, and
 * the row stays only so the browser can be told why.
 */
enum UploadPhase: string
{
    /** Declared and accepted; no byte has arrived yet. */
    case READY = 'ready';

    /** Chunks are arriving. */
    case UPLOADING = 'uploading';

    /** Exactly the declared size arrived and passed every check; the file waits for its consumer. */
    case COMPLETE = 'complete';

    /** Refused after the start; the error code and sentence say why, and there is no file. */
    case FAILED = 'failed';

    /**
     * @return bool Whether chunks are still accepted in this phase
     */
    public function isReceiving(): bool
    {
        return $this === self::READY || $this === self::UPLOADING;
    }

    /**
     * @return bool Whether an upload in this phase keeps a temporary file on disk
     */
    public function holdsFile(): bool
    {
        return $this !== self::FAILED;
    }
}
