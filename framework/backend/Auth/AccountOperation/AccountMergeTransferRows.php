<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;

/** Explicit row ids selected for an account merge transfer. */
final readonly class AccountMergeTransferRows
{
    /** @var list<int> Identity ids in ascending order without duplicates */
    public array $identities;

    /** @var list<int> Passkey credential ids in ascending order without duplicates */
    public array $passkeyCredentials;

    /** @var list<int> Second-factor ids in ascending order without duplicates */
    public array $secondFactors;

    /** @var list<int> Second-factor backup-code ids in ascending order without duplicates */
    public array $secondFactorBackupCodes;

    /**
     * @param list<int> $identities Identity ids to transfer
     * @param list<int> $passkeyCredentials Passkey credential ids to transfer
     * @param list<int> $secondFactors Second-factor ids to transfer
     * @param list<int> $secondFactorBackupCodes Second-factor backup-code ids to transfer
     * @throws InvalidFormatException When a selection is not a list of positive integers
     */
    public function __construct(
        array $identities = [],
        array $passkeyCredentials = [],
        array $secondFactors = [],
        array $secondFactorBackupCodes = [],
    ) {
        $this->identities = self::normalize($identities);
        $this->passkeyCredentials = self::normalize($passkeyCredentials);
        $this->secondFactors = self::normalize($secondFactors);
        $this->secondFactorBackupCodes = self::normalize($secondFactorBackupCodes);
    }

    /**
     * @param list<int> $ids Selected row ids
     * @return list<int> Positive row ids in ascending order without duplicates
     * @throws InvalidFormatException When the selection is not a list of positive integers
     */
    private static function normalize(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new InvalidFormatException('Transfer row ids must be a list of positive integers.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidFormatException('Transfer row ids must be a list of positive integers.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
