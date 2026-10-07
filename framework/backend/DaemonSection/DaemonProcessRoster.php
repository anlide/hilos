<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** Complete process roster from one node's master. */
final readonly class DaemonProcessRoster
{
    /**
     * @param list<DaemonWorkerPicture> $workers Live workers sorted by index
     * @param ?list<string> $unplacedAgentIds Leader's sorted unhosted ids, or null on a follower
     * @param int $workerRestarts24h Successful crash replacements in the last day
     * @throws InvalidFormatException When a field, uniqueness, or order is invalid
     */
    public function __construct(
        public array $workers,
        public ?array $unplacedAgentIds,
        public int $workerRestarts24h,
    ) {
        if (!array_is_list($workers) || $workerRestarts24h < 0) {
            throw new InvalidFormatException('Daemon process roster carries invalid workers or restart count');
        }
        $previousIndex = 0;
        $seenAgents = [];
        foreach ($workers as $worker) {
            if (!$worker instanceof DaemonWorkerPicture || $worker->index <= $previousIndex) {
                throw new InvalidFormatException('Daemon process workers must have unique sorted indexes');
            }
            $previousIndex = $worker->index;
            foreach ($worker->agents as $agent) {
                if (isset($seenAgents[$agent->id])) {
                    throw new InvalidFormatException('Daemon process roster repeats an agent id');
                }
                $seenAgents[$agent->id] = true;
            }
        }
        if ($unplacedAgentIds !== null) {
            if (!array_is_list($unplacedAgentIds)) {
                throw new InvalidFormatException('Daemon unplaced agents must be a list');
            }
            $previousId = null;
            foreach ($unplacedAgentIds as $id) {
                if (!is_string($id) || $id === '' || ($previousId !== null && strcmp($previousId, $id) >= 0)) {
                    throw new InvalidFormatException('Daemon unplaced agents must be non-empty, unique, and sorted');
                }
                $previousId = $id;
            }
        }
    }
}
