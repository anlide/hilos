<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeEnableSignalData - initiator -> leader payload for PROTECTED_MODE_ENABLE.
 *
 * The initiator (the backup restore agent today, other operations later) is passive: it
 * asks the leader to freeze the cluster and identifies itself so the leader can leave it
 * running and recognize its browser again when the verification window opens. The
 * connection itself is frozen out meanwhile, like every other. The leader records these fields on
 * {@see ProtectedModeRuntime}, drives the two-phase freeze, and
 * signals PROTECTED_MODE_READY back to this initiator once every node has quiesced.
 *
 * The initiator is named twice over, and both are needed: the accept key is the socket that asked,
 * and the session token hash is the browser it asked from. A reload or a second tab arrives with a
 * new accept key and the same cookie, so the hash is the half that still knows the person who
 * started the operation once the window lets them back in (HIL-655, HIL-718). Only the hash
 * travels - the token itself opens the account.
 */
final class ProtectedModeEnableSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the operation name the initiator will run. */
    public const string operation = 'operation';

    /** Payload key: the initiator connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /** Payload key: the hash of the initiator browser's session token. */
    public const string initiatorSessionTokenHash = 'initiatorSessionTokenHash';

    /** Payload key: the initiator agent type left running during the freeze. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the initiator agent index left running during the freeze. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: the node id that hosts the initiator agent, or null off-cluster. */
    public const string initiatorNodeId = 'initiatorNodeId';

    /**
     * @param string $operation Operation the initiator will run under the freeze
     * @param ?string $initiatorAcceptKey Accept key of the initiator connection, or null when the
     *                                    freeze was asked for by something without a connection
     * @param ?string $initiatorSessionTokenHash Hash of the session token behind that connection, or null
     *                                           when the asker has no browser session - the accept key
     *                                           names one socket, this names every tab of one browser
     * @param string $initiatorAgentType Agent type left running during the freeze
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param ?string $initiatorNodeId Node id that hosts the initiator agent, or null on a
     *                                 single-node installation, which has no node ids at all
     */
    public function __construct(
        public readonly string $operation,
        public readonly ?string $initiatorAcceptKey,
        public readonly ?string $initiatorSessionTokenHash,
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly ?string $initiatorNodeId,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
            self::initiatorSessionTokenHash => $this->initiatorSessionTokenHash,
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
            self::initiatorNodeId => $this->initiatorNodeId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no operation or no initiator agent type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            operation: self::requireString($data, self::operation),
            initiatorAcceptKey: self::optionalString($data, self::initiatorAcceptKey),
            initiatorSessionTokenHash: self::optionalString($data, self::initiatorSessionTokenHash),
            initiatorAgentType: self::requireString($data, self::initiatorAgentType),
            initiatorAgentIndex: self::optionalInt($data, self::initiatorAgentIndex),
            initiatorNodeId: self::optionalString($data, self::initiatorNodeId),
        );
    }
}
