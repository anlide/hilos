<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Agents\Hilos;

use Demo\ConcertTickets\Database\ConcertTicketsDbContext;
use Demo\ConcertTickets\Hilos;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportWriter;
use Hilos\HilosException;

/** Exports only the project's records belonging to the person. */
final class DataExportAgent extends AbstractDataExportAgent
{
    public const array READS_DB = [
        ...parent::READS_DB,
        ConcertTicketsDbContext::users,
    ];

    /**
     * @param int $userId Person whose profile is exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When the record cannot be exported
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $writer->section('concert-tickets', [
            'profile' => [
                'name' => isset(Hilos::$db->users[$userId]) ? Hilos::$db->users[$userId]->name : null,
            ],
        ]);
    }
}
