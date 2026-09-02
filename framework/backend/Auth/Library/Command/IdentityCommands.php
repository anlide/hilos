<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Taking a sign-in method off an account (HIL-722).
 *
 * The eighth group, and the first that removes rather than adds. It exists because
 * unlinking a passkey is not one write: the anchor row and the crypto sidecar are two
 * tables, and only the sign-in commands are allowed to know both. A project that called
 * the identity primitive on its own — as the chat demo did — took the anchor out and
 * left a credential that still signed its owner in, so the branching by identity type
 * belongs here, where every project inherits it, rather than in each demo that would
 * otherwise grow the same defect on its own.
 *
 * The order inside {@see unlink()} is the whole of what this group promises. It is the
 * one place a passkey stops existing, so a project reaches it the way it reaches the
 * register and login ceremonies: through the library agent that owns it.
 */
final class IdentityCommands extends AbstractLibraryCommands
{
    /**
     * Unlinks one of the acting user's sign-in methods, crypto half included.
     *
     * The profile's unlink door. The acting user is resolved here rather than passed in,
     * exactly as the ceremonies resolve theirs, so a project's handler is left with
     * nothing to get wrong about whose identity this is.
     *
     * Three steps, in this order and for this reason. The refusal to remove a last
     * sign-in method comes FIRST, before anything is written: a refusal has to leave
     * every row where it found it, and the primitive's own copy of the guard would fire
     * only after the credential was already gone. The credential goes SECOND and the
     * anchor THIRD, because an interruption between them has to leave the state that
     * closes the account rather than the one that opens it — an anchor without a
     * credential is a row the profile still lists and can be unlinked again, while a
     * credential without an anchor is a key that signs somebody in on a passkey they
     * were told they had removed.
     *
     * The primitive checks ownership and the last-method count again
     * ({@see Identities::deleteIdentity()}). That is not a duplicate to be cleaned up:
     * it is public, and a public write defends itself whoever calls it (HIL-377).
     * Ownership is why the cascade asks whose identity this is before it removes
     * anything: the primitive is what refuses a foreign identity, and it speaks after
     * the credential would already be gone — so an id belonging to somebody else is
     * left to it untouched, and the refusal costs that account nothing.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param int $identityId Identity id to unlink
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When the identity is not the acting user's, or is their last one
     * @throws HilosException When an identity or credential lookup or delete fails
     */
    public function unlink(string $acceptKey, int $identityId): void
    {
        $acting = $this->actingUser($acceptKey);

        if (count(Hilos::$db->identities->listByUser($acting->userId)) <= 1) {
            throw new ValidationException('cannot remove your only sign-in method');
        }

        if (
            Hilos::$db->identities[$identityId]?->userId === $acting->userId
            && Hilos::$db->identities[$identityId]?->type === IdentityType::PASSKEY
        ) {
            Hilos::$db->passkeyCredentials->deleteByIdentity($identityId);
        }

        Hilos::$db->identities->deleteIdentity($acting->userId, $identityId);
    }
}
