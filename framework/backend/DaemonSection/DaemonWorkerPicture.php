<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;

/** One live worker and the agents known to have started inside it. */
final readonly class DaemonWorkerPicture
{
    /**
     * @param int $index Positive worker index
     * @param string $kind Regular or monopolistic worker kind
     * @param ?int $pid Process id, or null when unavailable
     * @param ?int $memoryBytes RSS, or null when unavailable
     * @param list<DaemonAgentPicture> $agents Started agents sorted by id
     * @throws InvalidFormatException When a field or agent order is invalid
     */
    public function __construct(
        public int $index,
        public string $kind,
        public ?int $pid,
        public ?int $memoryBytes,
        public array $agents,
    ) {
        if ($index <= 0 || !in_array($kind, [WorkerConstants::TYPE_REGULAR, WorkerConstants::TYPE_MONOPOLISTIC], true)) {
            throw new InvalidFormatException('Daemon worker picture carries an invalid index or kind');
        }
        if (($pid !== null && $pid <= 0) || ($memoryBytes !== null && $memoryBytes < 0)) {
            throw new InvalidFormatException('Daemon worker picture carries an invalid pid or RSS');
        }
        if (!array_is_list($agents)) {
            throw new InvalidFormatException('Daemon worker agents must be a list');
        }
        $previousId = null;
        foreach ($agents as $agent) {
            if (!$agent instanceof DaemonAgentPicture || ($previousId !== null && strcmp($previousId, $agent->id) >= 0)) {
                throw new InvalidFormatException('Daemon worker agents must be unique and sorted by id');
            }
            $previousId = $agent->id;
        }
    }
}
