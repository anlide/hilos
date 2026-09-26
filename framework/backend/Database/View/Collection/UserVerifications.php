<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\View\Item\UserVerification;

/**
 * UserVerifications Db collection.
 *
 * Read-facing representation of the framework-owned hilos_user_verification
 * table. The issue/verify orchestration runs in
 * {@see VerificationService} against the object-layer
 * primitives ({@see ObjectUserVerifications}); no read API is exposed here
 * because the verification mechanism never surfaces to the frontend.
 *
 * @extends DbCollection<UserVerification, ObjectUserVerifications>
 */
final class UserVerifications extends DbCollection
{
    public const string DB_ITEM_CLASS = UserVerification::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserVerifications::class;

    /**
     * Deletes every code challenge of a person - the account is being erased (HIL-302).
     *
     * Bridged to the object collection, the one write this collection exposes; the object
     * carries each row out with its delete announcement.
     *
     * @param int $userId Person whose rows to delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->objectCollection->deleteForUser($userId);
    }
}
