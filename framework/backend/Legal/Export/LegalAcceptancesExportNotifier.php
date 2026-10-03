<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;

/** Tells the legal agent, after an erasure commits, that no file of acceptance records may outlive the account. */
final class LegalAcceptancesExportNotifier
{
    /**
     * Sent only where the legal agent is registered: a project without the legal section has no exports and
     * no recipient for the frame.
     *
     * @param int $userId Erased account
     * @throws InvalidArgumentException When the frame cannot be named or queued
     */
    public static function forgetUser(int $userId): void
    {
        if (!isset(Hilos::appClass()::getAgentSignalRoutes()[HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET])) {
            return;
        }
        Hilos::$sr?->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName(HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET),
            signalData: new AgentSignalData(data: new LegalAcceptancesExportForgetSignalData($userId)),
        );
    }
}
