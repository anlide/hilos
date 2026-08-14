<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Demo\Chat\Agents\ChatAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * AccountMergeSignalData - admin account-merge request payload (Hilos user page → ChatAgent, HIL-378).
 *
 * The admin merge action runs on the page's HILOS_INDEX agent, but the merge
 * itself must execute on the session-owning ChatAgent (it owns the users,
 * messages, and sessions truth sources plus the force-logout mechanics). The
 * page forwards the request point-to-point; the agent runs
 * {@see ChatAgent::handleAccountMerge()} and acks the
 * initiating connection ({@see $acceptKey}) with success or failure.
 *
 * Routing is by signal name (ACCOUNT_MERGE_REQUEST → ChatAgent) via the agent's
 * AGENT_SIGNALS declaration.
 */
final class AccountMergeSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @param string $acceptKey Initiating connection accept key to ack with the result
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
        public readonly string $acceptKey,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'survivorUserId' => $this->survivorUserId,
            'loserUserId' => $this->loserUserId,
            'acceptKey' => $this->acceptKey,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names neither side of the merge or no key to ack
     */
    public static function fromArray(array $data): static
    {
        return new static(
            survivorUserId: self::requireInt($data, 'survivorUserId'),
            loserUserId: self::requireInt($data, 'loserUserId'),
            acceptKey: self::requireString($data, 'acceptKey'),
        );
    }
}
