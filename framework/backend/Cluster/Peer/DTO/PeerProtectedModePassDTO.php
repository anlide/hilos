<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that carries one minted pass across the cluster.
 *
 * The only one of the three verification frames with a payload, and it carries the hash, never the
 * key: the clear value exists in the operator's terminal and in the verifier's browser and nowhere
 * else. Like {@see PeerProtectedModeVerifyDTO} it travels both ways - initiator to leader, then
 * leader to every follower - so each node holds the same {@see ProtectedModeRuntime::$passHashes}
 * and a verifier may present its key to whichever node it lands on. What is deliberately NOT fanned
 * is the admission the pass later earns: an accept key means something only on the node holding
 * that connection.
 */
final class PeerProtectedModePassDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode pass frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_pass';

    /** @var string Frame key carrying the hash of the minted pass */
    public const string FIELD_PASS_HASH = 'passHash';

    /**
     * @param string $passHash SHA-256 of the minted pass
     */
    public function __construct(
        public readonly string $passHash,
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
     * Serializes the pass frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_PASS_HASH => $this->passHash,
        ];
    }

    /**
     * Restores a pass frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static((string)$data[self::FIELD_PASS_HASH]);
    }
}
