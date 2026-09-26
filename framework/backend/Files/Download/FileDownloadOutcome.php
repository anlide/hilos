<?php

declare(strict_types=1);

namespace Hilos\Files\Download;

/**
 * How serving a registry file the viewer may see ended (HIL-138).
 *
 * The browser hears only a status; these are the reasons behind the refusals, which the files
 * library writes to its journal - each one is fixed in a different place.
 */
enum FileDownloadOutcome
{
    /** The file is answered, by the daemon's body or by X-Accel */
    case SERVED;

    /** The row names a stored file the storage does not hold: 404 */
    case MISSING_ON_DISK;

    /** No X-Accel is configured and the file is above what the daemon sends itself: 500 */
    case TOO_LARGE_TO_SEND_DIRECTLY;

    /** The file is there and its read failed: 404 */
    case UNREADABLE;
}
