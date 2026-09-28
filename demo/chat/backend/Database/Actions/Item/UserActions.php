<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Item\UserActions as FrameworkUserActions;
use Hilos\HilosException;

/**
 * UserActions - write operations for a single User item.
 *
 * @property-read ObjectUser $object
 */
final class UserActions extends FrameworkUserActions
{
    /**
     * Tombstones this user as the loser of an account merge (HIL-378).
     *
     * A merged loser is soft-deleted, never dropped: the row stays for audit and
     * partial reversibility, with `merged_into` recording the survivor it folded
     * into and `block` closing its login. Both columns are written in one sync so
     * the tombstone is atomic within the surrounding merge transaction. The caller
     * ({@see SessionsLibraryAgent::applyAccountMerge()}) writes it inside the
     * transaction the framework opened; that this user is not itself already merged
     * was vouched for one step earlier, by
     * {@see SessionsLibraryAgent::assertMergeable()}.
     *
     * @param int $survivorId Survivor user id this account is folded into
     * @throws ItemNotFoundForUpdateException When the user is not found (id is null)
     * @throws HilosException On database error or other failure
     */
    public function tombstone(int $survivorId): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for tombstone (id is null)');
        }

        $this->object->mergedInto = $survivorId;
        $this->object->block = true;
        $this->object->sync();
    }
}
