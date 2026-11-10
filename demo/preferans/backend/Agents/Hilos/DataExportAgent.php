<?php

declare(strict_types=1);

namespace Demo\Preferans\Agents\Hilos;

use Demo\Preferans\Database\PreferansDbContext;
use Demo\Preferans\Hilos;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportWriter;
use Hilos\HilosException;

/** Exports only the project's records belonging to the person. */
final class DataExportAgent extends AbstractDataExportAgent
{
    public const array READS_DB = [
        ...parent::READS_DB,
        PreferansDbContext::users,
    ];

    /**
     * @param int $userId Person whose profile is exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When the record cannot be exported
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $writer->section('preferans', [
            'profile' => [
                'name' => isset(Hilos::$db->users[$userId]) ? Hilos::$db->users[$userId]->name : null,
            ],
        ]);
    }
}
