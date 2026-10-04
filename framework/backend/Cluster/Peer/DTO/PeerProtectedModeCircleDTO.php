<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that carries the verifier circle photographed at the freeze across the cluster.
 *
 * The photograph travels whole rather than one member at a time, because it has a single moment of
 * truth - the ready relay on the initiator's node (HIL-1118) - and a part of it read at that moment
 * and a part read later would describe two different halls. On the wire it is the count of named
 * people and the hashes of their session tokens; no address and no clear token ever leave the node
 * that photographed it.
 *
 * Like {@see PeerProtectedModePassDTO} it travels both ways - initiator to leader, then leader to
 * every follower master - so each node holds the same {@see ProtectedModeRuntime::$circleSessionTokenHashes}
 * and decides admission against its own copy. A verifier holding a pass brings its key to whichever
 * node it lands on; a member of the circle brings nothing - its tab is recognized by the session it
 * already carries - so whichever node that tab reconnects to has to hold the photograph already.
 */
final class PeerProtectedModeCircleDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode circle frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_circle';

    /** @var string Frame key naming the initiator agent type */
    public const string FIELD_INITIATOR_AGENT_TYPE = 'initiatorAgentType';

    /** @var string Frame key naming the initiator agent index */
    public const string FIELD_INITIATOR_AGENT_INDEX = 'initiatorAgentIndex';

    /** @var string Frame key carrying how many people the circle named at the freeze */
    public const string FIELD_NAMED_COUNT = 'namedCount';

    /** @var string Frame key carrying the session token hashes of the named people who were online */
    public const string FIELD_SESSION_TOKEN_HASHES = 'sessionTokenHashes';

    /**
     * @param string $initiatorAgentType Type of the agent that initiated the freeze
     * @param ?int $initiatorAgentIndex Index of that agent, or null for a singleton
     * @param VerifierCircleSnapshot $snapshot The circle as the initiator's node photographed it
     */
    public function __construct(
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly VerifierCircleSnapshot $snapshot,
    ) {
    }

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the circle frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_INITIATOR_AGENT_TYPE => $this->initiatorAgentType,
            self::FIELD_INITIATOR_AGENT_INDEX => $this->initiatorAgentIndex,
            self::FIELD_NAMED_COUNT => $this->snapshot->namedCount,
            self::FIELD_SESSION_TOKEN_HASHES => $this->snapshot->sessionTokenHashes,
        ];
    }

    /**
     * Restores a circle frame from its wire array.
     *
     * A hash list with anything but non-empty strings in it is refused whole rather than thinned:
     * the hashes are who the window lets in, and a list read without one of them would lock out a
     * person the photograph named.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the initiator identity or payload is malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            $agentType = self::requireString($data, self::FIELD_INITIATOR_AGENT_TYPE);
            $agentIndex = self::optionalInt($data, self::FIELD_INITIATOR_AGENT_INDEX);
            $namedCount = self::requireInt($data, self::FIELD_NAMED_COUNT);
            $rawHashes = self::requireArray($data, self::FIELD_SESSION_TOKEN_HASHES);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer protected-mode circle frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($agentType === '') {
            throw new PeerTransportException('Peer protected-mode circle frame is malformed: initiatorAgentType is empty');
        }

        if (!array_is_list($rawHashes)) {
            throw new PeerTransportException('Peer protected-mode circle frame carries a non-list of session token hashes');
        }

        $sessionTokenHashes = [];
        foreach ($rawHashes as $sessionTokenHash) {
            if (!is_string($sessionTokenHash) || $sessionTokenHash === '') {
                throw new PeerTransportException('Peer protected-mode circle frame carries a malformed session token hash');
            }

            $sessionTokenHashes[] = $sessionTokenHash;
        }

        return new static($agentType, $agentIndex, new VerifierCircleSnapshot($namedCount, $sessionTokenHashes));
    }
}
