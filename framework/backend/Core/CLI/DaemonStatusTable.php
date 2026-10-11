<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

use Hilos\Constants\DaemonConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Utils\Helpers\StringHelper;

/** Shared terminal table for daemon:status and daemon:monitor. */
final class DaemonStatusTable
{
    private const int LABEL_WIDTH = 18;
    private const int VALUE_WIDTH = 45;

    /**
     * @param string $statusWord Current connection verdict
     * @param ?DaemonStatusDTO $status Latest daemon reply, or null when none arrived
     * @return string Complete terminal table without a trailing blank line
     */
    public static function render(string $statusWord, ?DaemonStatusDTO $status): string
    {
        $measurement = $status?->measurements;
        $unknown = DaemonConstants::VALUE_NOT_AVAILABLE;
        $cores = $measurement?->cores === null ? $unknown
            : $measurement->cores . ' (' . $measurement->coresBound . ')';
        $daemonLoad = $measurement?->daemonCores === null ? $unknown
            : $measurement->daemonCores . ' cores, master ' . $measurement->masterCores;
        $machineLoad = $measurement?->machineLoad === null ? $unknown : $measurement->machineLoad . '%';
        $masterMemory = $measurement?->masterMemory === null ? $unknown
            : StringHelper::formatBytes($measurement->masterMemory);
        $workersMemory = $measurement?->workersMemory === null ? $unknown
            : StringHelper::formatBytes($measurement->workersMemory) . ', ' . $measurement->workersMeasured . ' workers';
        $ceiling = $unknown;
        if ($measurement?->memoryCeiling !== null) {
            $ceiling = StringHelper::formatBytes($measurement->memoryCeiling)
                . ' (' . $measurement->memoryCeilingBound . ')';
            if ($measurement->masterMemory !== null && $measurement->workersMemory !== null) {
                $ceiling .= ', ' . round(100 * ($measurement->masterMemory + $measurement->workersMemory)
                    / $measurement->memoryCeiling) . '%';
            }
        }
        $phpLimit = $status === null ? $unknown : ($measurement->phpMemoryLimit === null
            ? 'unlimited' : StringHelper::formatBytes($measurement->phpMemoryLimit) . ' per process');

        $rows = [
            ['Status', $statusWord],
            ['Uptime', $status === null ? $unknown : StringHelper::formatUptime($status->uptime)],
            ['Node cores', $cores],
            ['Daemon load', $daemonLoad],
            ['Machine load', $machineLoad],
            ['Master memory', $masterMemory],
            ['Workers memory', $workersMemory],
            ['Memory ceiling', $ceiling],
            ['PHP memory_limit', $phpLimit],
            ['Workers Regular', $status === null ? $unknown : $status->workersRegular . ' / ' . $status->workersMaxRegular],
            ['Workers Mono', $status === null ? $unknown : (string)$status->workersMonopolistic],
        ];

        $border = '+' . str_repeat('-', self::LABEL_WIDTH + 2) . '+' . str_repeat('-', self::VALUE_WIDTH + 2) . "+\n";
        $table = $border . sprintf('| %-*s | %-*s |' . "\n", self::LABEL_WIDTH, 'Metric', self::VALUE_WIDTH, 'Value') . $border;
        foreach ($rows as [$label, $value]) {
            $table .= sprintf('| %-*s | %-*s |' . "\n", self::LABEL_WIDTH, $label, self::VALUE_WIDTH, $value);
        }

        return $table . $border;
    }
}
