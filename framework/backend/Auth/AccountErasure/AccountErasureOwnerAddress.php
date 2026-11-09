<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountErasure;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;

/**
 * A durable agent address, independent of its current process or placement.
 * SCAFFOLD: durable assignment storage will consume this value (HIL-1936).
 */
final readonly class AccountErasureOwnerAddress
{
    public const int OWNER_TYPE_MAX_CHARACTERS = 191;
    public const int OWNER_INDEX_MAX_CHARACTERS = 191;

    /**
     * @param string $ownerType Nonempty stable agent type, preserved literally
     * @param ?string $ownerIndex Nonempty literal agent index, or null for an unindexed agent
     * @throws EmptyValueException When either supplied address component is empty
     * @throws ValueTooLongException When an address component exceeds its character limit
     */
    public function __construct(public string $ownerType, public ?string $ownerIndex = null)
    {
        if ($ownerType === '' || $ownerIndex === '') {
            throw new EmptyValueException('An erasure owner address cannot contain an empty component.');
        }
        if (mb_strlen($ownerType, 'UTF-8') > self::OWNER_TYPE_MAX_CHARACTERS) {
            throw new ValueTooLongException('The erasure owner type is too long.');
        }
        if ($ownerIndex !== null && mb_strlen($ownerIndex, 'UTF-8') > self::OWNER_INDEX_MAX_CHARACTERS) {
            throw new ValueTooLongException('The erasure owner index is too long.');
        }
    }

    /**
     * @return string Agent identity with its literal optional index
     */
    public function agentId(): string
    {
        if ($this->ownerIndex === null) {
            return $this->ownerType;
        }

        return $this->ownerType . AgentConstants::ID_SEPARATOR . $this->ownerIndex;
    }

    /**
     * @param self $other Address to compare
     * @return bool Whether both literal components match, including case and null
     */
    public function equals(self $other): bool
    {
        return $this->ownerType === $other->ownerType && $this->ownerIndex === $other->ownerIndex;
    }
}
