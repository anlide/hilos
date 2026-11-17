<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\AccountMergeFenceSnapshot;
use Hilos\Database\Actions\Collection\AccountMergeFencesActions;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\View\Item\AccountMergeFence;
use Hilos\HilosException;

/**
 * Array offsets are collectionKey:decimalRowId; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountMergeFence, ObjectAccountMergeFences>
 * @property-read AccountMergeFencesActions $actions
 * @method ObjectAccountMergeFences|null getObjectCollection()
 * @method AccountMergeFence|null first()
 * @method AccountMergeFence|null last()
 * @method AccountMergeFence offsetGet(mixed $offset)
 */
class AccountMergeFences extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountMergeFence::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountMergeFences::class;

    /**
     * @param string $key Exact composite key
     * @return ?AccountMergeFenceSnapshot Current durable coordinates without refreshing local applied progress
     * @throws HilosException When the key, binding or storage refuses the read
     */
    public function fresh(string $key): ?AccountMergeFenceSnapshot
    {
        return $this->getObjectCollection()->fresh($key);
    }
}
