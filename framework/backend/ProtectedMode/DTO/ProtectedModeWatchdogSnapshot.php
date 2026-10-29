<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\ProtectedModeStuckVerdict;

/**
 * Current watchdog judgment and effective configuration, independent of mail deduplication.
 *
 * Thresholds and recipients are resolved before capture; only the worker-reported restart policy
 * may still be unknown. This value carries no notification history and changes no freeze state.
 */
final class ProtectedModeWatchdogSnapshot extends BaseDTO
{
    /** Payload key: the current cause, or null while clear. */
    public const string watchdogVerdict = 'watchdogVerdict';

    /** Payload key: nodes still pending for the current overdue quiesce round. */
    public const string watchdogPendingNodeIds = 'watchdogPendingNodeIds';

    /** Payload key: the effective quiesce threshold in seconds. */
    public const string watchdogQuiesceTimeoutSeconds = 'watchdogQuiesceTimeoutSeconds';

    /** Payload key: the effective silence threshold in seconds. */
    public const string watchdogSilenceTimeoutSeconds = 'watchdogSilenceTimeoutSeconds';

    /** Payload key: the effective reminder interval in seconds. */
    public const string watchdogAlertIntervalSeconds = 'watchdogAlertIntervalSeconds';

    /** Payload key: the same recipients used for watchdog mail. */
    public const string watchdogAlertEmails = 'watchdogAlertEmails';

    /** Payload key: the last worker-reported manual restart policy, or null before a report. */
    public const string watchdogManualRestartIsNormal = 'watchdogManualRestartIsNormal';

    /**
     * @param ?string $watchdogVerdict Current verdict value, or null while clear
     * @param list<string> $watchdogPendingNodeIds Pending nodes for quiesce-overdue, empty otherwise
     * @param int $watchdogQuiesceTimeoutSeconds Effective quiesce threshold in seconds
     * @param int $watchdogSilenceTimeoutSeconds Effective silence threshold in seconds
     * @param int $watchdogAlertIntervalSeconds Effective reminder interval in seconds
     * @param list<string> $watchdogAlertEmails Configured recipient addresses, possibly empty
     * @param ?bool $watchdogManualRestartIsNormal Worker-reported policy, or null before a report
     */
    public function __construct(
        public readonly ?string $watchdogVerdict,
        public readonly array $watchdogPendingNodeIds,
        public readonly int $watchdogQuiesceTimeoutSeconds,
        public readonly int $watchdogSilenceTimeoutSeconds,
        public readonly int $watchdogAlertIntervalSeconds,
        public readonly array $watchdogAlertEmails,
        public readonly ?bool $watchdogManualRestartIsNormal,
    ) {
    }

    /** @return array<string, mixed> Current judgment and configuration */
    public function toArray(): array
    {
        return [
            self::watchdogVerdict => $this->watchdogVerdict,
            self::watchdogPendingNodeIds => $this->watchdogPendingNodeIds,
            self::watchdogQuiesceTimeoutSeconds => $this->watchdogQuiesceTimeoutSeconds,
            self::watchdogSilenceTimeoutSeconds => $this->watchdogSilenceTimeoutSeconds,
            self::watchdogAlertIntervalSeconds => $this->watchdogAlertIntervalSeconds,
            self::watchdogAlertEmails => $this->watchdogAlertEmails,
            self::watchdogManualRestartIsNormal => $this->watchdogManualRestartIsNormal,
        ];
    }

    /**
     * @param array<string, mixed> $data Snapshot payload
     * @return static Restored snapshot
     * @throws InvalidFormatException When a field is mistyped, a verdict is unknown or required configuration is absent
     */
    public static function fromArray(array $data): static
    {
        $verdict = self::optionalString($data, self::watchdogVerdict);
        if ($verdict !== null && ProtectedModeStuckVerdict::tryFrom($verdict) === null) {
            throw new InvalidFormatException('Payload carries an unknown watchdog verdict');
        }

        return new static(
            watchdogVerdict: $verdict,
            watchdogPendingNodeIds: self::requireStringList($data, self::watchdogPendingNodeIds),
            watchdogQuiesceTimeoutSeconds: self::requireInt($data, self::watchdogQuiesceTimeoutSeconds),
            watchdogSilenceTimeoutSeconds: self::requireInt($data, self::watchdogSilenceTimeoutSeconds),
            watchdogAlertIntervalSeconds: self::requireInt($data, self::watchdogAlertIntervalSeconds),
            watchdogAlertEmails: self::requireStringList($data, self::watchdogAlertEmails),
            watchdogManualRestartIsNormal: self::optionalBool($data, self::watchdogManualRestartIsNormal),
        );
    }

    /**
     * @param array<string, mixed> $data Snapshot payload
     * @param string $key Required list key
     * @return list<string> Required string list, possibly empty
     * @throws InvalidFormatException When the key is absent or holds anything but a string list
     */
    private static function requireStringList(array $data, string $key): array
    {
        return self::optionalStringList($data, $key)
            ?? throw new InvalidFormatException('Payload carries no list of strings under key ' . $key);
    }
}
