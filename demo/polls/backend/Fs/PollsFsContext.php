<?php

declare(strict_types=1);

namespace Demo\Polls\Fs;

use Demo\Polls\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared directories of the project's account exports and of the administrators' exports of acceptance records. */
final class PollsFsContext extends FsContext
{
    /** Registers storage shared by the project's nodes and their web servers. */
    public function configure(): void
    {
        $this->registerDirectory(
            FsContext::DATA_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/data_export',
            DirectoryScope::CLUSTER,
        );
        $this->registerDirectory(
            FsContext::LEGAL_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/legal_export',
            DirectoryScope::CLUSTER,
        );
    }
}
