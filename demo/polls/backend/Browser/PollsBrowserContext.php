<?php

declare(strict_types=1);

namespace Demo\Polls\Browser;

use Demo\Polls\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * PollsBrowserContext - Browser-facing context ($browser layer) for polls.
 *
 * Supplies the one computed browser field the demo needs: a user row's runtime
 * presence summary (presence + online session count), read from the RT
 * connections presence source over the framework source fan-out. The users table
 * and the single-user detail table both declare these as COMPUTED fields, so
 * without this override a connected user would render as offline / 0 sessions.
 * Settings rows are delivered by the framework self-snapshot path
 * (HilosSettingsTable), not here.
 */
final class PollsBrowserContext extends BrowserContext
{
    /**
     * Answers the ADMIN page-level gate from the demo's user storage: the durable
     * user row's admin flag, the same flag the admin:grant command writes. Runs in
     * whatever worker serves the gated page (the framework admin surface is served
     * by the hilos index agent). A missing row denies, as it always did; a read that failed no
     * longer does (HIL-575). It answered 403 to an administrator whose only problem was that
     * this worker had not been declared a reader of the collection, and it left nothing behind
     * to say so.
     *
     * @param int $userId Authenticated durable user id
     * @return bool Whether this user may access ADMIN-level pages and actions
     */
    public function isAdmin(int $userId): bool
    {
        return (Hilos::$db->users[$userId] ?? null)?->admin === true;
    }

    /**
     * Computes the demo's browser fields named by page/table configs.
     *
     * @param string $browserKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        if (
            $field === HilosUserPresenceSummary::presence
            || $field === HilosUserPresenceSummary::onlineSessionCount
        ) {
            return $this->computeUserPresenceSummaryField($field, $rowKey);
        }

        return parent::computeBrowserField(
            $browserKey,
            $field,
            $rowKey,
            $acceptKey,
            $pageParams,
            $browserParams,
            $sources,
        );
    }

    /**
     * Resolves who is behind an accept key from the demo's runtime connection
     * registry, where the handshake records the acceptKey -> user mapping. Lets the
     * page access gate identify the subscriber.
     *
     * A registry row is written for every connection the handshake sees, so no row
     * means the row has not crossed the RT sync into this worker yet rather than
     * "nobody is there" - the frame waits instead of being refused as anonymous
     * (HIL-599). A demo that mounts no registry at all is the opposite case and answers a
     * settled nobody: where the answer can never arrive there is nothing to wait for.
     *
     * Fail-closed stops there, and that is a change of behaviour (HIL-575). It belongs where
     * the question is who this connection is; it does not belong to a read that was refused,
     * which says nothing about the connection and everything about the wiring. Answered
     * "anonymous", a refusal signed everybody out of a node that was merely wired wrong.
     *
     * @param string $acceptKey Subscriber accept key
     * @return ConnectionIdentity User behind the connection, or the pending state
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        try {
            $connections = Hilos::$rt?->connections;
            if ($connections === null) {
                return ConnectionIdentity::resolved(null);
            }

            $connection = $connections[$acceptKey];

            return $connection === null
                ? ConnectionIdentity::pending()
                : ConnectionIdentity::resolved($connection->userId);
        } catch (RtCollectionNotFoundException) {
            return ConnectionIdentity::resolved(null);
        }
    }

    /**
     * Computes a runtime connection presence summary field for a user row.
     *
     * @param string $field Summary field name (presence or online session count)
     * @param int|string $rowKey User id row key
     * @return mixed Summary field value, or null when runtime state is unavailable
     */
    private function computeUserPresenceSummaryField(string $field, int|string $rowKey): mixed
    {
        $userId = (int) $rowKey;
        if ($userId <= 0 || (string) $userId !== (string) $rowKey) {
            return null;
        }

        try {
            $summary = Hilos::$rt?->connections->summaryForUser($userId);
        } catch (RtCollectionNotFoundException) {
            return null;
        }

        return match ($field) {
            HilosUserPresenceSummary::presence => $summary?->presence,
            HilosUserPresenceSummary::onlineSessionCount => $summary?->onlineSessionCount,
            default => null,
        };
    }
}
