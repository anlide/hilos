<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Fs\FsException;

/** A batch reached the open journal file, but making that file ready failed. */
final class AnalyticsJournalRotationException extends FsException
{
}
