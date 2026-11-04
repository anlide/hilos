<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** Full leader snapshot for a slave; no secrets, accept keys or remote clocks travel here. */
final class PeerProtectedModeSyncDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_protected_mode_sync';

    public const string FIELD_SYNC_ID = 'syncId';

    public const string FIELD_PHASE = 'phase';

    public const string FIELD_ENTRY_MODE = 'entryMode';

    public const string FIELD_PAYLOAD = 'payload';

    public const string FIELD_BARRIER_CLOSED = 'barrierClosed';

    public const string FIELD_PASS_HASHES = 'passHashes';

    public const string FIELD_ADMITTED_SESSION_TOKEN_HASHES = 'admittedSessionTokenHashes';

    public const string FIELD_CIRCLE_NAMED_COUNT = 'circleNamedCount';

    public const string FIELD_CIRCLE_SESSION_TOKEN_HASHES = 'circleSessionTokenHashes';

    /**
     * @param string $syncId Correlation of this requested snapshot
     * @param string $phase Target phase
     * @param ?string $entryMode Full freeze or direct window; null when inactive
     * @param ?ProtectedModeQuiesceData $payload Freeze descriptor; null when inactive
     * @param bool $barrierClosed Whether this generation's initial confirmations have closed
     * @param list<string> $passHashes Minted pass hashes
     * @param list<string> $admittedSessionTokenHashes Admitted browser session hashes
     * @param int $circleNamedCount People named by the existing photograph
     * @param list<string> $circleSessionTokenHashes Existing circle session hashes
     * @throws PeerTransportException When any snapshot field or combination is invalid
     */
    public function __construct(
        public readonly string $syncId,
        public readonly string $phase,
        public readonly ?string $entryMode,
        public readonly ?ProtectedModeQuiesceData $payload,
        public readonly bool $barrierClosed,
        public readonly array $passHashes,
        public readonly array $admittedSessionTokenHashes,
        public readonly int $circleNamedCount,
        public readonly array $circleSessionTokenHashes,
    ) {
        if (trim($syncId) === '' || !in_array($phase, PeerProtectedModeSyncedDTO::PHASES, true) || $circleNamedCount < 0) {
            throw new PeerTransportException('Peer protected-mode sync has an invalid correlation, phase or circle count');
        }
        foreach ([$passHashes, $admittedSessionTokenHashes, $circleSessionTokenHashes] as $hashes) {
            if (!array_is_list($hashes) || array_any($hashes, static fn(mixed $hash): bool => !is_string($hash) || trim($hash) === '')) {
                throw new PeerTransportException('Peer protected-mode sync requires lists of nonempty hashes');
            }
        }
        if ($phase === ProtectedModeRuntime::PHASE_INACTIVE) {
            if ($entryMode !== null || $payload !== null || $barrierClosed || $passHashes !== []
                || $admittedSessionTokenHashes !== [] || $circleNamedCount !== 0 || $circleSessionTokenHashes !== []) {
                throw new PeerTransportException('Peer protected-mode inactive sync must carry an empty mode');
            }
            return;
        }
        if (!in_array($entryMode, [ProtectedModeRuntime::ENTRY_MODE_FREEZE, ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW], true)
            || $payload === null || $payload->freezeId === null || trim($payload->freezeId) === ''
            || trim($payload->operation) === '' || trim($payload->initiatorAgentType) === '') {
            throw new PeerTransportException('Peer protected-mode occupied sync requires an entryMode and a generation descriptor');
        }
        if ($entryMode === ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW
            && $phase !== ProtectedModeRuntime::PHASE_VERIFYING) {
            throw new PeerTransportException('Peer protected-mode direct window requires verifying phase');
        }
        if ($phase === ProtectedModeRuntime::PHASE_ACTIVATING && $barrierClosed) {
            throw new PeerTransportException('Peer protected-mode activating sync cannot claim a closed barrier');
        }
        if ($entryMode === ProtectedModeRuntime::ENTRY_MODE_FREEZE && !$barrierClosed
            && in_array($phase, [ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::PHASE_VERIFYING], true)) {
            throw new PeerTransportException('Peer protected-mode established full freeze requires a closed barrier');
        }
    }

    /** @return string Wire message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_SYNC_ID => $this->syncId,
            self::FIELD_PHASE => $this->phase,
            self::FIELD_ENTRY_MODE => $this->entryMode,
            self::FIELD_PAYLOAD => $this->payload?->toArray(),
            self::FIELD_BARRIER_CLOSED => $this->barrierClosed,
            self::FIELD_PASS_HASHES => $this->passHashes,
            self::FIELD_ADMITTED_SESSION_TOKEN_HASHES => $this->admittedSessionTokenHashes,
            self::FIELD_CIRCLE_NAMED_COUNT => $this->circleNamedCount,
            self::FIELD_CIRCLE_SESSION_TOKEN_HASHES => $this->circleSessionTokenHashes,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Validated slave snapshot
     * @throws PeerTransportException When the payload is malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            $payload = self::optionalArray($data, self::FIELD_PAYLOAD);
            return new static(
                self::requireString($data, self::FIELD_SYNC_ID),
                self::requireString($data, self::FIELD_PHASE),
                self::optionalString($data, self::FIELD_ENTRY_MODE),
                $payload === null ? null : ProtectedModeQuiesceData::fromArray($payload),
                self::requireBool($data, self::FIELD_BARRIER_CLOSED),
                self::requireArray($data, self::FIELD_PASS_HASHES),
                self::requireArray($data, self::FIELD_ADMITTED_SESSION_TOKEN_HASHES),
                self::requireInt($data, self::FIELD_CIRCLE_NAMED_COUNT),
                self::requireArray($data, self::FIELD_CIRCLE_SESSION_TOKEN_HASHES),
            );
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Peer protected-mode sync frame is malformed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
