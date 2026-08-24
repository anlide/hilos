<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;

/**
 * SessionCarrier - carries live authenticated sessions across a database replacement (HIL-479).
 *
 * When a restore puts a different database under a running node, every session created after
 * the archive was taken is simply absent from it, and the people holding those sessions are
 * logged out at the moment they are watching the restore they started. This mechanism spans
 * the swap in two halves: {@see capture()} photographs the live sessions while the old database
 * is still there, and {@see carryOver()} re-creates them once the new one is in place.
 *
 * It belongs to the session layer rather than to backup because it is about sessions surviving
 * a database swap, whatever performs the swap; restore is today's only caller, not the owner.
 *
 * The mechanism is deliberately unable to guess. A session is re-created only for a person the
 * restored database recognizes by the very same identity pairs, and a single disagreement
 * between those pairs drops the session instead of picking a winner - handing someone another
 * account is a worse outcome than handing them the login screen.
 */
final class SessionCarrier
{
    /**
     * Photographs the live authenticated sessions, before the database is replaced.
     *
     * Must run while the node is frozen and the old database is still mounted: that is the only
     * window where the set of connections no longer grows and the rows behind them can still be
     * read. Connections are the source, one entry per session token however many sockets share
     * it. An impersonated session is captured for the real administrator behind the takeover,
     * because the right to look at someone else's account was granted in a database that is
     * about to stop existing.
     *
     * The window is real, and until HIL-664 it was not: the freeze stops the node's agents, and
     * an agent used to empty the connections collection on its way out, so this ran over rows
     * that had been wiped fifteen milliseconds earlier - the sentence above described a hall
     * that had just been cleared. The rows now outlive the agent, which is what makes "frozen"
     * the right moment rather than merely the current one. Nothing can be lost between the
     * photograph and the swap either: under the freeze a new tab meets the stub and a login has
     * no agent to be handled by, so the set this reads cannot grow behind it.
     *
     * The session token is what is photographed, so the source asked for is the session stage
     * of the connection base (HIL-509): a project whose runtime connections do not reach
     * {@see HilosSessionConnections} - because it carries no browser sessions, or keeps no
     * connections at all - yields an empty snapshot, and the whole mechanism quietly does
     * nothing. There is nothing to carry over for a project that has no sessions to lose.
     *
     * @return list<SessionCarryover> Live authenticated sessions, deduplicated by token
     * @throws DatabaseException When a session or identity lookup fails
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match its collection
     */
    public static function capture(): array
    {
        $connections = Hilos::$rt?->sessionConnectionsSource();
        if ($connections === null) {
            return [];
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

            // The impersonator is the human being at the keyboard; the impersonated user is the
            // account being looked at, and it is not the one whose login has to survive.
            $userId = $session->impersonatorUserId ?? $session->userId;
            if ($userId === null) {
                continue;
            }

            $identities = [];
            foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
                $identities[] = new SessionIdentityRef($identity->type, $identity->identifier);
            }

            $snapshot[$token] = new SessionCarryover(
                token: $token,
                createdAt: $session->createdAt,
                expiresAt: $session->expiresAt,
                identities: $identities,
            );
        }

        return array_values($snapshot);
    }

    /**
     * Re-creates the captured sessions in the database that replaced the old one.
     *
     * Must run after the process has re-read the new database and before the node thaws: the
     * clients are told to reload the moment protected mode lifts, and a session that is not in
     * place by then is one the browser will not find. One failing row is logged and does not
     * stop the rest - a restore that succeeded must not be undone by a session that could not
     * be written.
     *
     * @param list<SessionCarryover> $snapshot Sessions captured before the swap
     * @return SessionCarryResult Sessions written and sessions lost
     */
    public static function carryOver(array $snapshot): SessionCarryResult
    {
        $now = TimeHelper::getSqlDateTime();
        $carried = 0;
        $dropped = 0;

        foreach ($snapshot as $carryover) {
            if ($carryover->expiresAt !== null && $carryover->expiresAt <= $now) {
                $dropped++;
                continue;
            }

            try {
                $userId = self::resolveUserId($carryover->identities);
                if ($userId === null) {
                    $dropped++;
                    continue;
                }

                $session = Hilos::$db->sessions->actions->carryOver(
                    $carryover->token,
                    $userId,
                    $carryover->createdAt,
                    $carryover->expiresAt,
                );
            } catch (HilosException $e) {
                Logger::error('Session carry-over could not restore a session', [
                    'error' => $e->getMessage(),
                ]);
                $dropped++;
                continue;
            }

            // A token that already holds a row came back inside the archive: its owner stays
            // logged in without this pass, so it is neither carried nor lost.
            if ($session !== null) {
                $carried++;
            }
        }

        return new SessionCarryResult($carried, $dropped);
    }

    /**
     * Resolves who a captured session belongs to, in the restored database.
     *
     * Null on every uncertainty: no pair is known there (the person is absent from the archive),
     * or the pairs point at different accounts (the archive knows the same email and the same
     * phone number as two people, and choosing between them is not this mechanism's call).
     *
     * @param list<SessionIdentityRef> $identities Identity pairs captured before the swap
     * @return ?int User id in the restored database, or null when it is not unambiguous
     * @throws DatabaseException When an identity lookup fails
     * @throws LogicException When the identities collection's class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     */
    private static function resolveUserId(array $identities): ?int
    {
        $resolved = null;
        foreach ($identities as $reference) {
            $userId = Hilos::$db->identities->findByIdentity($reference->type, $reference->identifier)?->userId;
            if ($userId === null) {
                continue;
            }
            if ($resolved !== null && $resolved !== $userId) {
                return null;
            }
            $resolved = $userId;
        }

        return $resolved;
    }
}
