<?php

declare(strict_types=1);

namespace Hilos\Tests\Fixtures;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;

/** Isolated real public volume for production Status lifecycle fixtures. */
final class StatusPublicVolume
{
    private ?FsContext $previous;
    private string $path;

    /** Mounts only the feature's declared directory; no installation path is touched. */
    public function __construct()
    {
        $this->previous = Hilos::$fs;
        $this->path = sys_get_temp_dir() . '/hilos-status-lifecycle-' . uniqid('', true);
        Hilos::$fs = new StatusPublicVolumeContext($this->path);
        Hilos::$fs->configure();
    }

    /** Discards temporary and public fixture files, then restores the prior mount. */
    public function close(): void
    {
        try {
            if (is_dir($this->path)) {
                foreach (FsPath::entries($this->path) as $name) {
                    FsPath::delete($this->path . '/' . $name);
                }
                FsPath::removeDirectory($this->path);
            }
        } finally {
            Hilos::$fs = $this->previous;
        }
    }
}

/** The fixture registers the same reserved CLUSTER directory as production. */
final class StatusPublicVolumeContext extends FsContext
{
    /** @param string $path Isolated temporary directory */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers only public Status publication. */
    public function configure(): void
    {
        $this->registerDirectory(self::STATUS, $this->path, DirectoryScope::CLUSTER);
    }
}
