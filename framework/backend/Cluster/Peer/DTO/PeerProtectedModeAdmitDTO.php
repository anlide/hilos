<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * A verifier admitted by a code travels from the 101 master through the leader to every master.
 * Receivers admit only while verifying and while this pass stands on their row. Both values are
 * hashes; the clear code and the session token stay at the browser (HIL-1305).
 */
final class PeerProtectedModeAdmitDTO extends PeerDTO
{
    /** @var string Wire message type for a verifier admission */
    public const string MESSAGE_TYPE = 'peer_protected_mode_admit';

    /** @var string Frame key carrying the hash of the presented pass */
    public const string FIELD_PASS_HASH = 'passHash';

    /** @var string Frame key carrying the verifier session hash */
    public const string FIELD_SESSION_TOKEN_HASH = 'sessionTokenHash';

    /**
     * @param string $passHash Hash of the pass presented at the 101 master
     * @param string $sessionTokenHash Hash of the admitted browser session
     */
    public function __construct(
        public readonly string $passHash,
        public readonly string $sessionTokenHash,
    ) {
    }

    /**
     * @return string Wire message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_PASS_HASH => $this->passHash,
            self::FIELD_SESSION_TOKEN_HASH => $this->sessionTokenHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When either hash is missing or empty
     */
    public static function fromArray(array $data): static
    {
        try {
            $passHash = self::requireString($data, self::FIELD_PASS_HASH);
            $sessionTokenHash = self::requireString($data, self::FIELD_SESSION_TOKEN_HASH);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer protected-mode admit frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($passHash === '' || $sessionTokenHash === '') {
            throw new PeerTransportException('Peer protected-mode admit frame is malformed: a hash is empty');
        }

        return new static($passHash, $sessionTokenHash);
    }
}
