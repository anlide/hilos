<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\Exception;

use Hilos\ProtectedMode\ProtectedModeFreezeStore;

/**
 * A freeze was left on disk and this daemon cannot tell what it said (HIL-482).
 *
 * Raised by {@see ProtectedModeFreezeStore::load()} for a file that exists but cannot be read,
 * does not decode, carries a version this build does not know, or holds no row. It refuses the
 * startup rather than degrading to "no freeze": the file exists precisely because a node was
 * frozen when it went down, so reading it as absent would open a node whose destructive
 * operation never finished - the single failure protected mode exists to prevent.
 *
 * A missing file is not this: a node that was never frozen leaves nothing behind, and that is
 * the ordinary startup.
 */
final class ProtectedModeFreezeUnreadableException extends ProtectedModeException
{
}
