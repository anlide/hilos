<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Project runtime context for the framework-singleton tests.
 *
 * It stands for a project that mounts the backup runtime row and registers nothing else,
 * so what the tests read back is the framework's own representation and not a project's.
 * Protected mode is not mounted here on purpose: the framework mounts it itself through
 * {@see RtContext::mountFeatureRuntime()}, and the tests call that where they need it.
 */
final class FrameworkRuntimeSingletonTestRtContext extends RtContext
{
    /**
     * Mounts the backup runtime singleton the way a project with backups enabled does.
     */
    public function configure(): void
    {
        $this->_stateItems[StateBackupRuntime::RT_ITEM] = StateBackupRuntime::create();
    }
}
