<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportWriter;
use Hilos\HilosException;

/** Exports only the project's records belonging to the person. */
final class DataExportAgent extends AbstractDataExportAgent
{
    public const array READS_DB = [
        ...parent::READS_DB,
        TasksDbContext::users,
    ];

    /**
     * @param int $userId Person whose profile and content are exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When a record or attachment cannot be exported
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $writer->section('tasks', [
            'profile' => ['name' => Hilos::$db->users[$userId]?->name],
        ]);
    }
}
