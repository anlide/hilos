<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModePassSignalData - initiator -> daemon payload for PROTECTED_MODE_PASS.
 *
 * One more verifier is let into the verification window. The clear key never rides this frame:
 * the initiator mints it, prints it back to the operator's terminal and sends only its SHA-256
 * on, so the only copies of the key that ever exist are the operator's and the verifier's
 * ({@see ProtectedModeRuntime::$passHashes}).
 *
 * It carries and authorizes by the same identity as {@see ProtectedModeVerifySignalData}
 * ({@see ClusterProtectedMode::onPass()} for the clustered half).
 */
final class ProtectedModePassSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type minting the pass. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index minting the pass. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: SHA-256 of the minted pass. */
    public const string passHash = 'passHash';

    /**
     * @param string $initiatorAgentType Agent type minting the pass
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param string $passHash SHA-256 of the minted pass
     */
    public function __construct(
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly string $passHash,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
            self::passHash => $this->passHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentIndex = $data[self::initiatorAgentIndex] ?? null;

        return new static(
            initiatorAgentType: (string)$data[self::initiatorAgentType],
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
            passHash: (string)$data[self::passHash],
        );
    }
}
