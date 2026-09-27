<?php

declare(strict_types=1);

namespace Demo\Tasks\Fs;

use Demo\Tasks\Hilos;
use Hilos\Fs\Context\FsContext;

/** Shared archive directory of the project's account exports. */
final class TasksFsContext extends FsContext
{
    /** Registers storage shared by the project's nodes and their web servers. */
    public function configure(): void
    {
        $this->registerDirectory(FsContext::DATA_EXPORT, dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/data_export');
    }
}
