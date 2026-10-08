<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Environment\EnvResolution;

/** One cataloged key as read on its owning node, before any browser masking. */
final readonly class NodeEnvironmentKey
{
    /**
     * @param string $key Catalog key
     * @param string $type Catalog type
     * @param bool $required Whether a value is mandatory
     * @param bool $declaredByFramework Whether the framework declared this key
     * @param bool $sensitive Whether its value must be masked for administrators
     * @param bool $perNode Whether different nodes are expected to differ
     * @param bool $adminViewVisible Whether a view-mode viewer may see its value
     * @param EnvResolution $process Value and source held by this process
     * @param ?EnvResolution $disk Value and source a restart would choose, null without drift
     */
    public function __construct(
        public string $key,
        public string $type,
        public bool $required,
        public bool $declaredByFramework,
        public bool $sensitive,
        public bool $perNode,
        public bool $adminViewVisible,
        public EnvResolution $process,
        public ?EnvResolution $disk,
    ) {
    }
}
