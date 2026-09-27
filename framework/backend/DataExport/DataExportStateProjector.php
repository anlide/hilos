<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\DataExport\DTO\DataExportStateSignalData;
use Hilos\Database\View\Item\DataExport;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/** The same archive node for initial payloads and subsequent state signals. */
final class DataExportStateProjector
{
    public const string SECTION = DataExportStateSignalData::dataExport;

    /**
     * @param ?DataExport $export The person's request, if any
     * @return ?array{state: string, requestedAt: int, finishedAt: ?int, expiresAt: ?int, sizeBytes: ?int} Portable state node
     */
    public static function nodeFor(?DataExport $export): ?array
    {
        return $export === null ? null : [
            DataExportStateSignalData::state => $export->state,
            DataExportStateSignalData::requestedAt => TimeHelper::sqlToMs($export->requestedAt),
            DataExportStateSignalData::finishedAt => $export->finishedAt === null ? null : TimeHelper::sqlToMs($export->finishedAt),
            DataExportStateSignalData::expiresAt => $export->expiresAt === null ? null : TimeHelper::sqlToMs($export->expiresAt),
            DataExportStateSignalData::sizeBytes => $export->sizeBytes,
        ];
    }

    /**
     * @param int $userId Person whose archive state is sought
     * @return DataExportStateSignalData Current state, including absence
     * @throws HilosException When the request cannot be read
     */
    public static function stateFor(int $userId): DataExportStateSignalData
    {
        return new DataExportStateSignalData(self::nodeFor(Hilos::$db->dataExports->ofUser($userId)));
    }
}
