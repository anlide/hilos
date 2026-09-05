<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Backup\Anonymization\AnonymizationStartupGuard;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * The verifier circle as it was the moment a node froze (HIL-643).
 *
 * Photographed by the same pass that photographs the hall ({@see SessionCarrier::capture()}),
 * under the freeze and before the database is replaced, because that is the only moment when
 * both halves of the question are still true: the circle table is about to be overwritten by
 * the archive's own, and the set of live connections has stopped growing.
 *
 * Two numbers come out of it and both are needed, which is why this is an object rather than a
 * bare list. The hashes are what the verification window lets in; the count of named members is
 * what says whether an empty intersection means "nobody was named" or "nobody named was online"
 * - a distinction the freeze cannot recover later, since by then the circle table belongs to the
 * restored database.
 */
final readonly class VerifierCircleSnapshot
{
    /**
     * @param int $namedCount How many people the circle named at the freeze
     * @param list<string> $sessionTokenHashes Session token hashes of the named people who were online
     */
    public function __construct(
        public int $namedCount,
        public array $sessionTokenHashes,
    ) {
    }

    /**
     * Photographs the circle against the hall, before the database is replaced.
     *
     * An installation that does not declare backup has no circle table at all, so it is asked
     * nothing: the early return is what keeps three of the four demos free of a query for a
     * table their migrations never created ({@see AnonymizationStartupGuard} refuses the same
     * way for the same reason). A project with no session-carrying connections yields an empty
     * photograph for the reason {@see SessionCarrier::capture()} does - there are no browsers to
     * recognize.
     *
     * The owner of a session is read the way the carry-over reads it: the impersonator when
     * there is one, because the right to look at somebody else's account was granted in a
     * database that is about to stop existing, and the person at the keyboard is who the circle
     * named. A guest session has no owner and can be in no circle.
     *
     * Duplicates are removed by session token rather than by hash, so two tabs of one browser
     * count once - the same key {@see SessionCarrier::capture()} deduplicates by.
     *
     * @return self The circle as it stood, with the hashes of the members who were online
     * @throws DatabaseException When the circle, identity or session lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    public static function capture(): self
    {
        if (!Hilos::hasFeature(HilosFeature::BACKUP)) {
            return new self(0, []);
        }

        $namedUserIds = [];
        $namedCount = 0;
        foreach (Hilos::$db->verifierCircle->listAll() as $member) {
            $namedCount++;
            $identity = Hilos::$db->identities->findByIdentity($member->identityType, $member->identifier);
            if ($identity !== null) {
                $namedUserIds[$identity->userId] = true;
            }
        }

        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($namedUserIds === [] || $connections === null) {
            return new self($namedCount, []);
        }

        $snapshot = [];
        foreach ($connections->findAuthenticated() as $connection) {
            $token = $connection->sessionToken;
            if ($token === null || isset($snapshot[$token])) {
                continue;
            }

            $session = Hilos::$db->sessions->findByToken($token);
            if ($session === null) {
                continue;
            }

            $userId = $session->impersonatorUserId ?? $session->userId;
            if ($userId === null || !isset($namedUserIds[$userId])) {
                continue;
            }

            $snapshot[$token] = ProtectedModeRuntime::hashSessionToken($token);
        }

        return new self($namedCount, array_values($snapshot));
    }
}
