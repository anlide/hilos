<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Current protected-mode state for the Maintenance section (server to browser).
 *
 * Every field describes the freeze or its verifier circle, without identifying a person.
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
        ];
    }

    /**
     * @return array<string, WireField> Non-personal classification of every frame field
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
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of the section state
     * @return static Restored section state
     * @throws InvalidFormatException When a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): static
    {
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
        );
    }
}
