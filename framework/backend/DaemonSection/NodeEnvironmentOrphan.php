<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** A dotenv entry absent from the catalog; its value never leaves the node as text. */
final readonly class NodeEnvironmentOrphan
{
    /**
     * @param string $key Uncataloged key
     * @param string $value Raw dotenv value, retained only inside the reading
     */
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
