<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\BaseDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\HilosException;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;

/** Strict command boundary; absent selectors impose no restriction. */
final class AccountErasureProbeRequest extends BaseDTO
{
    public const string ACTION = 'action';
    public const string FRAME_KIND = 'frameKind';
    public const string OPERATION_ID = AccountOperationAskSignalData::operationId;
    public const string STAGE = AccountOperationAskSignalData::stage;
    public const string ASSIGNMENT_ID = AccountOperationAskSignalData::assignmentId;
    public const string FRAME = 'frame';
    public const string COPIES = 'copies';

    /**
     * @param string $agentId Existing local source address
     * @param AccountErasureProbeAction $action Test operation
     * @param ?AccountErasureProbeFrameKind $frameKind Required kind for arm
     * @param ?int $operationId Optional arm operation selector
     * @param ?string $stage Optional arm stage selector
     * @param ?int $assignmentId Optional arm assignment selector
     * @param ?WorkerAgentMessageDTO $frame Complete captured envelope for replay
     * @param ?int $copies Replay count, exactly one or two
     * @throws InvalidFormatException When the action's fields or replay source are inconsistent
     */
    public function __construct(
        public readonly string $agentId,
        public readonly AccountErasureProbeAction $action,
        public readonly ?AccountErasureProbeFrameKind $frameKind = null,
        public readonly ?int $operationId = null,
        public readonly ?string $stage = null,
        public readonly ?int $assignmentId = null,
        public readonly ?WorkerAgentMessageDTO $frame = null,
        public readonly ?int $copies = null,
    ) {
        if ($agentId === '' || ($operationId !== null && $operationId <= 0)
            || ($assignmentId !== null && $assignmentId <= 0) || $stage === '') {
            throw new InvalidFormatException('Erasure probe requires a source and valid selectors');
        }
        if ($action === AccountErasureProbeAction::Arm) {
            if ($frameKind === null) {
                throw new InvalidFormatException('Arming an erasure probe requires frameKind');
            }
        } elseif ($frameKind !== null || $operationId !== null || $stage !== null || $assignmentId !== null) {
            throw new InvalidFormatException('Erasure probe selectors belong only to arm');
        }
        if ($action === AccountErasureProbeAction::Replay) {
            if ($frame === null || !in_array($copies, [1, 2], true)) {
                throw new InvalidFormatException('Erasure probe replay requires a frame and one or two copies');
            }
            self::validateFrame($frame);
            if (!self::isFrom($frame, $agentId)) {
                throw new InvalidFormatException('Erasure probe replay names another source');
            }
        } elseif ($frame !== null || $copies !== null) {
            throw new InvalidFormatException('Erasure probe frame and copies belong only to replay');
        }
    }

    /**
     * Restore through the product hydrator, refusing its untyped fallback or any lossy normalization.
     *
     * @param array<string, mixed> $data Complete captured worker envelope
     * @return WorkerAgentMessageDTO Typed, losslessly restored envelope
     * @throws InvalidFormatException When the envelope is incomplete, foreign or malformed
     */
    public static function restoreFrame(array $data): WorkerAgentMessageDTO
    {
        $signal = self::requireArray($data, WorkerAgentMessageDTO::SIGNAL);
        $wrapper = self::requireArray($signal, SignalPayloadConstants::FIELD_DATA);
        if (self::requireString($data, WorkerDTO::TYPE) !== WorkerAgentMessageDTO::MESSAGE_TYPE
            || self::requireString($signal, SignalPayloadConstants::FIELD_DATA_TYPE) !== AgentSignalData::class
            || !in_array(self::requireString($wrapper, SignalPayloadConstants::FIELD_DATA_TYPE), [
                AccountOperationAskSignalData::class,
                AccountOperationAnswerSignalData::class,
            ], true)) {
            throw new InvalidFormatException('Erasure probe accepts only typed account-operation agent envelopes');
        }
        try {
            $frame = WorkerAgentMessageDTO::fromArray($data);
        } catch (HilosException $error) {
            throw new InvalidFormatException('Erasure probe frame could not be restored', 0, $error);
        }
        self::validateFrame($frame);
        if (!self::sameEnvelope($data, $frame->toArray())) {
            throw new InvalidFormatException('Erasure probe frame must preserve the complete captured envelope');
        }
        return $frame;
    }

    /**
     * @param WorkerAgentMessageDTO $frame Candidate outgoing envelope
     * @return ?AccountErasureProbeFrameKind Recognized kind, or null for other traffic
     */
    public static function kindOf(WorkerAgentMessageDTO $frame): ?AccountErasureProbeFrameKind
    {
        if ($frame->signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL
            || !$frame->signal->data instanceof AgentSignalData) {
            return null;
        }
        return match (true) {
            $frame->signal->data->data instanceof AccountOperationAskSignalData => AccountErasureProbeFrameKind::Ask,
            $frame->signal->data->data instanceof AccountOperationAnswerSignalData => AccountErasureProbeFrameKind::Answer,
            default => null,
        };
    }

    /**
     * @param WorkerAgentMessageDTO $frame Candidate outgoing envelope
     * @param string $agentId Expected source address
     * @return bool Whether both the worker envelope and inner source name that agent
     */
    public static function isFrom(WorkerAgentMessageDTO $frame, string $agentId): bool
    {
        $identity = AgentId::fromId($agentId);
        return $frame->agentId === $agentId
            && $frame->signal->signalSource->getSource() === SignalSource::AGENT
            && $frame->signal->signalSource->getType() === $identity->type
            && $frame->signal->signalSource->getIndex() === $identity->index;
    }

    /**
     * @param WorkerAgentMessageDTO $frame Envelope to hold or replay
     * @throws InvalidFormatException When the envelope is not an addressed account-operation agent frame
     */
    public static function validateFrame(WorkerAgentMessageDTO $frame): void
    {
        if ($frame->agentId === null || $frame->agentId === ''
            || $frame->signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL
            || !$frame->signal->data instanceof AgentSignalData
            || !($frame->signal->data->data instanceof AccountOperationAskSignalData
                || $frame->signal->data->data instanceof AccountOperationAnswerSignalData)
            || !self::isFrom($frame, $frame->agentId)) {
            throw new InvalidFormatException('Erasure probe requires an account-operation frame from its addressed agent');
        }
        $payload = $frame->signal->data->data;
        if ($payload->operationId <= 0 || $payload->assignmentId <= 0 || $payload->stage === '') {
            throw new InvalidFormatException('Erasure probe frame requires positive identifiers and a stage');
        }
    }

    /** @return array<string, mixed> Request with absent optional fields omitted */
    public function toArray(): array
    {
        $data = [AgentConstants::FIELD_AGENT_ID => $this->agentId, self::ACTION => $this->action->value];
        foreach ([
            self::FRAME_KIND => $this->frameKind?->value,
            self::OPERATION_ID => $this->operationId,
            self::STAGE => $this->stage,
            self::ASSIGNMENT_ID => $this->assignmentId,
            self::FRAME => $this->frame?->toArray(),
            self::COPIES => $this->copies,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data Complete command request
     * @return static Strictly parsed request
     * @throws InvalidFormatException When fields, action or field combinations are invalid
     */
    public static function fromArray(array $data): static
    {
        if (array_diff(array_keys($data), [
            AgentConstants::FIELD_AGENT_ID, self::ACTION, self::FRAME_KIND, self::OPERATION_ID,
            self::STAGE, self::ASSIGNMENT_ID, self::FRAME, self::COPIES,
        ]) !== []) {
            throw new InvalidFormatException('Unknown erasure probe request field');
        }
        $action = AccountErasureProbeAction::tryFrom(self::requireString($data, self::ACTION));
        $kind = array_key_exists(self::FRAME_KIND, $data)
            ? AccountErasureProbeFrameKind::tryFrom(self::requireString($data, self::FRAME_KIND)) : null;
        if ($action === null || (array_key_exists(self::FRAME_KIND, $data) && $kind === null)) {
            throw new InvalidFormatException('Unknown erasure probe action or frame kind');
        }
        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            action: $action,
            frameKind: $kind,
            operationId: array_key_exists(self::OPERATION_ID, $data) ? self::requireInt($data, self::OPERATION_ID) : null,
            stage: array_key_exists(self::STAGE, $data) ? self::requireString($data, self::STAGE) : null,
            assignmentId: array_key_exists(self::ASSIGNMENT_ID, $data) ? self::requireInt($data, self::ASSIGNMENT_ID) : null,
            frame: array_key_exists(self::FRAME, $data) ? self::restoreFrame(self::requireArray($data, self::FRAME)) : null,
            copies: array_key_exists(self::COPIES, $data) ? self::requireInt($data, self::COPIES) : null,
        );
    }

    /**
     * Key order is immaterial; types, every field and all nested metadata must survive hydration.
     *
     * @param array<string, mixed> $received Captured transport value
     * @param array<string, mixed> $restored Hydrated transport value
     * @return bool Whether restoration preserved every key and value
     */
    private static function sameEnvelope(array $received, array $restored): bool
    {
        if (count($received) !== count($restored)) {
            return false;
        }
        foreach ($received as $key => $value) {
            if (!array_key_exists($key, $restored)) {
                return false;
            }
            if (is_array($value) && is_array($restored[$key])) {
                if (!self::sameEnvelope($value, $restored[$key])) {
                    return false;
                }
            } elseif ($value !== $restored[$key]) {
                return false;
            }
        }
        return true;
    }
}
