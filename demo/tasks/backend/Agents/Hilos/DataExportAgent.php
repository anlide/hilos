<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportTime;
use Hilos\DataExport\DataExportWriter;
use Hilos\HilosException;

/** Exports only the project's records belonging to the person. */
final class DataExportAgent extends AbstractDataExportAgent
{
    public const array READS_DB = [
        ...parent::READS_DB,
        TasksDbContext::users,
        TasksDbContext::userRenames,
    ];

    /**
     * @param int $userId Person whose profile and content are exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When a record or attachment cannot be exported
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $renames = [];
        foreach (Hilos::$db->userRenames->byTarget($userId) as $rename) {
            $renames[] = [
                'from' => $rename->oldName,
                'to' => $rename->newName,
                'at' => DataExportTime::iso($rename->timestamp),
            ];
        }
        $writer->section('tasks', [
            'profile' => ['name' => Hilos::$db->users[$userId]?->name],
            'renames' => $renames,
        ]);
    }
}
