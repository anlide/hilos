<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DataExport\DTO\DataExportForgetUserSignalData;
use Hilos\Hilos;

/** Sends account erasure to the owner of the archive after the erasure commits. */
final class DataExportNotifier
{
    /**
     * @param int $userId Erased account
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public static function forgetUser(int $userId): void
    {
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER),
            signalData: new AgentSignalData(data: new DataExportForgetUserSignalData($userId)),
        );
    }
}
