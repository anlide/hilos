<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\ProtectedMode\ProtectedModeStuckVerdict;

/**
 * Current protected-mode state for the Maintenance section (server to browser).
 *
 * Freeze diagnostics are not personal; watchdog recipients stay hidden from a viewer,
 * and the manual restart policy follows its settings catalog's visibility verdict.
 * `isInitiator` is that viewer's own verdict and is filled in per delivery; the frame
 * never carries a session hash, an accept key, or a pass.
 */
final class HilosMaintenanceStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string phase = 'phase';
    public const string operation = 'operation';
    public const string manual = 'manual';
    public const string startedAt = 'startedAt';
    public const string enteredFromBrowser = 'enteredFromBrowser';
    public const string passCount = 'passCount';
    public const string circleNamed = 'circleNamed';
    public const string circleAdmitted = 'circleAdmitted';
    public const string initiatorAgentType = 'initiatorAgentType';
    public const string initiatorNodeId = 'initiatorNodeId';
    public const string activatedAt = 'activatedAt';
    public const string progressAt = 'progressAt';
    public const string isInitiator = 'isInitiator';
    public const string diagnosticsNodeId = 'diagnosticsNodeId';
    public const string stoppedAgentCount = 'stoppedAgentCount';
    public const string preservedAgentTypes = 'preservedAgentTypes';
    public const string agentStartGateClosed = 'agentStartGateClosed';
    public const string watchdogVerdict = 'watchdogVerdict';
    public const string watchdogPendingNodeIds = 'watchdogPendingNodeIds';
    public const string watchdogQuiesceTimeoutSeconds = 'watchdogQuiesceTimeoutSeconds';
    public const string watchdogSilenceTimeoutSeconds = 'watchdogSilenceTimeoutSeconds';
    public const string watchdogAlertIntervalSeconds = 'watchdogAlertIntervalSeconds';
    public const string watchdogAlertEmails = 'watchdogAlertEmails';
    public const string watchdogManualRestartIsNormal = 'watchdogManualRestartIsNormal';

    /**
     * @param string $phase Protected-mode phase from the runtime row
     * @param ?string $operation Operation name, or null when inactive
     * @param bool $manual Whether this is a manual maintenance window
     * @param ?string $startedAt ISO-8601 UTC (Z) time the freeze began, or null when inactive
     * @param bool $enteredFromBrowser Whether a browser session requested the freeze
     * @param int $passCount Passes issued in the current verification window
     * @param int $circleNamed People named in the captured verifier circle
     * @param int $circleAdmitted Browsers admitted by that circle
     * @param ?string $initiatorAgentType Agent type of the initiator, or null when inactive
     * @param ?string $initiatorNodeId Node hosting the initiator agent, or null when inactive
     * @param ?string $activatedAt ISO-8601 UTC (Z) time the freeze reached active, or null before it does
     * @param ?string $progressAt ISO-8601 UTC (Z) time of the last progress mark, or null when none
     * @param bool $isInitiator Whether this viewer belongs to the initiating session
     * @param ?string $diagnosticsNodeId Node the diagnostic tuple describes, or null until published
     * @param ?int $stoppedAgentCount Agents this node is holding stopped, or null until published
     * @param ?list<string> $preservedAgentTypes Agent types left running, or null until published
     * @param ?bool $agentStartGateClosed Whether agent start is closed, or null until published
     * @param ?string $watchdogVerdict Current watchdog cause, or null while clear or unpublished
     * @param list<string> $watchdogPendingNodeIds Pending nodes for quiesce-overdue, empty otherwise
     * @param ?int $watchdogQuiesceTimeoutSeconds Effective quiesce threshold in seconds, or null until published
     * @param ?int $watchdogSilenceTimeoutSeconds Effective silence threshold in seconds, or null until published
     * @param ?int $watchdogAlertIntervalSeconds Effective reminder interval in seconds, or null until published
     * @param ?list<string> $watchdogAlertEmails Effective recipients, null until published, empty when configured so
     * @param ?bool $watchdogManualRestartIsNormal Worker-reported manual restart policy, or null before a report
     */
    public function __construct(
        public readonly string $phase,
        public readonly ?string $operation,
        public readonly bool $manual,
        public readonly ?string $startedAt,
        public readonly bool $enteredFromBrowser,
        public readonly int $passCount,
        public readonly int $circleNamed,
        public readonly int $circleAdmitted,
        public readonly ?string $initiatorAgentType,
        public readonly ?string $initiatorNodeId,
        public readonly ?string $activatedAt,
        public readonly ?string $progressAt,
        public readonly bool $isInitiator,
        public readonly ?string $diagnosticsNodeId,
        public readonly ?int $stoppedAgentCount,
        public readonly ?array $preservedAgentTypes,
        public readonly ?bool $agentStartGateClosed,
        public readonly ?string $watchdogVerdict = null,
        public readonly array $watchdogPendingNodeIds = [],
        public readonly ?int $watchdogQuiesceTimeoutSeconds = null,
        public readonly ?int $watchdogSilenceTimeoutSeconds = null,
        public readonly ?int $watchdogAlertIntervalSeconds = null,
        public readonly ?array $watchdogAlertEmails = null,
        public readonly ?bool $watchdogManualRestartIsNormal = null,
    ) {
    }

    /**
     * @return array<string, mixed> State as sent to the browser
     */
    public function toArray(): array
    {
        return [
            self::phase => $this->phase,
            self::operation => $this->operation,
            self::manual => $this->manual,
            self::startedAt => $this->startedAt,
            self::enteredFromBrowser => $this->enteredFromBrowser,
            self::passCount => $this->passCount,
            self::circleNamed => $this->circleNamed,
            self::circleAdmitted => $this->circleAdmitted,
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorNodeId => $this->initiatorNodeId,
            self::activatedAt => $this->activatedAt,
            self::progressAt => $this->progressAt,
            self::isInitiator => $this->isInitiator,
            self::diagnosticsNodeId => $this->diagnosticsNodeId,
            self::stoppedAgentCount => $this->stoppedAgentCount,
            self::preservedAgentTypes => $this->preservedAgentTypes,
            self::agentStartGateClosed => $this->agentStartGateClosed,
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
     * Recipients are undeclared so the viewer bridge hides the whole field, including null and empty lists.
     *
     * @return array<string, WireField> Diagnostic and settings origins of the fields open to viewers
     */
    public static function wireFields(): array
    {
        return [
            self::phase => WireField::notPersonal(),
            self::operation => WireField::notPersonal(),
            self::manual => WireField::notPersonal(),
            self::startedAt => WireField::notPersonal(),
            self::enteredFromBrowser => WireField::notPersonal(),
            self::passCount => WireField::notPersonal(),
            self::circleNamed => WireField::notPersonal(),
            self::circleAdmitted => WireField::notPersonal(),
            self::initiatorAgentType => WireField::notPersonal(),
            self::initiatorNodeId => WireField::notPersonal(),
            self::activatedAt => WireField::notPersonal(),
            self::progressAt => WireField::notPersonal(),
            self::isInitiator => WireField::notPersonal(),
            self::diagnosticsNodeId => WireField::notPersonal(),
            self::stoppedAgentCount => WireField::notPersonal(),
            self::preservedAgentTypes => WireField::notPersonal(),
            self::agentStartGateClosed => WireField::notPersonal(),
            self::watchdogVerdict => WireField::notPersonal(),
            self::watchdogPendingNodeIds => WireField::notPersonal(),
            self::watchdogQuiesceTimeoutSeconds => WireField::notPersonal(),
            self::watchdogSilenceTimeoutSeconds => WireField::notPersonal(),
            self::watchdogAlertIntervalSeconds => WireField::notPersonal(),
            self::watchdogManualRestartIsNormal => WireField::setting(ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of the section state
     * @return static Restored section state
     * @throws InvalidFormatException When a required field is missing, a field is mistyped or the watchdog verdict is unknown
     */
    public static function fromArray(array $data): static
    {
        $watchdogVerdict = self::optionalString($data, self::watchdogVerdict);
        if ($watchdogVerdict !== null && ProtectedModeStuckVerdict::tryFrom($watchdogVerdict) === null) {
            throw new InvalidFormatException('Payload carries an unknown watchdog verdict');
        }

        return new static(
            phase: self::requireString($data, self::phase),
            operation: self::optionalString($data, self::operation),
            manual: self::requireBool($data, self::manual),
            startedAt: self::optionalString($data, self::startedAt),
            enteredFromBrowser: self::requireBool($data, self::enteredFromBrowser),
            passCount: self::requireInt($data, self::passCount),
            circleNamed: self::requireInt($data, self::circleNamed),
            circleAdmitted: self::requireInt($data, self::circleAdmitted),
            initiatorAgentType: self::optionalString($data, self::initiatorAgentType),
            initiatorNodeId: self::optionalString($data, self::initiatorNodeId),
            activatedAt: self::optionalString($data, self::activatedAt),
            progressAt: self::optionalString($data, self::progressAt),
            isInitiator: array_key_exists(self::isInitiator, $data)
                ? self::requireBool($data, self::isInitiator)
                : false,
            diagnosticsNodeId: self::optionalString($data, self::diagnosticsNodeId),
            stoppedAgentCount: self::optionalInt($data, self::stoppedAgentCount),
            preservedAgentTypes: self::optionalStringList($data, self::preservedAgentTypes),
            agentStartGateClosed: self::optionalBool($data, self::agentStartGateClosed),
            watchdogVerdict: $watchdogVerdict,
            watchdogPendingNodeIds: array_key_exists(self::watchdogPendingNodeIds, $data)
                ? self::optionalStringList($data, self::watchdogPendingNodeIds)
                    ?? throw new InvalidFormatException('Payload carries no list of strings under key ' . self::watchdogPendingNodeIds)
                : [],
            watchdogQuiesceTimeoutSeconds: self::optionalInt($data, self::watchdogQuiesceTimeoutSeconds),
            watchdogSilenceTimeoutSeconds: self::optionalInt($data, self::watchdogSilenceTimeoutSeconds),
            watchdogAlertIntervalSeconds: self::optionalInt($data, self::watchdogAlertIntervalSeconds),
            watchdogAlertEmails: self::optionalStringList($data, self::watchdogAlertEmails),
            watchdogManualRestartIsNormal: self::optionalBool($data, self::watchdogManualRestartIsNormal),
        );
    }

    /**
     * Copy of the shared frame with this viewer's initiator verdict.
     *
     * The shared frame keeps the flag false so a broadcast fingerprint cannot adopt
     * the first subscriber's answer.
     *
     * @param bool $isInitiator Whether this viewer belongs to the initiating session
     * @return self Frame for that viewer
     */
    public function withIsInitiator(bool $isInitiator): self
    {
        return new self(
            phase: $this->phase,
            operation: $this->operation,
            manual: $this->manual,
            startedAt: $this->startedAt,
            enteredFromBrowser: $this->enteredFromBrowser,
            passCount: $this->passCount,
            circleNamed: $this->circleNamed,
            circleAdmitted: $this->circleAdmitted,
            initiatorAgentType: $this->initiatorAgentType,
            initiatorNodeId: $this->initiatorNodeId,
            activatedAt: $this->activatedAt,
            progressAt: $this->progressAt,
            isInitiator: $isInitiator,
            diagnosticsNodeId: $this->diagnosticsNodeId,
            stoppedAgentCount: $this->stoppedAgentCount,
            preservedAgentTypes: $this->preservedAgentTypes,
            agentStartGateClosed: $this->agentStartGateClosed,
            watchdogVerdict: $this->watchdogVerdict,
            watchdogPendingNodeIds: $this->watchdogPendingNodeIds,
            watchdogQuiesceTimeoutSeconds: $this->watchdogQuiesceTimeoutSeconds,
            watchdogSilenceTimeoutSeconds: $this->watchdogSilenceTimeoutSeconds,
            watchdogAlertIntervalSeconds: $this->watchdogAlertIntervalSeconds,
            watchdogAlertEmails: $this->watchdogAlertEmails,
            watchdogManualRestartIsNormal: $this->watchdogManualRestartIsNormal,
        );
    }
}
