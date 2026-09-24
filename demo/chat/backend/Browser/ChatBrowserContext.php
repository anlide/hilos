<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Database\DatabaseException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\AbstractHilosMergeCandidatesTable;

/**
 * Chat demo browser-facing context.
 *
 * Supplies the chat-specific computed browser fields — user presence and
 * self-connection state — over the framework source fan-out. Settings rows are
 * delivered by the framework self-snapshot path (HilosSettingsTable), not here.
 */
final class ChatBrowserContext extends BrowserContext
{
    /**
     * Computes chat browser fields named by page/table configs.
     *
     * @param string $browserKey Browser table key
     * @param string $field Computed field name from the mirrored browser config
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params for this page subscription
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value, or null when unavailable
     * @throws PageInternalErrorException When a computed field cannot be resolved
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
        if ($field === AbstractHilosMergeCandidatesTable::FIELD_HAS_PASSWORD) {
            try {
                return Hilos::$db->identities->findPasswordByUser((int) $rowKey) !== null;
            } catch (DatabaseException|InvalidArgumentException|LogicException $exception) {
                throw new PageInternalErrorException('Password presence could not be resolved', $exception);
            }
        }

        if (
            $field === HilosUserPresenceSummary::presence
            || $field === HilosUserPresenceSummary::onlineSessionCount
        ) {
            return $this->computeUserPresenceSummaryField($field, $rowKey);
        }

        if (
            $field === SelfConnectionSignalData::messageRateLimitSecondsRemaining
            || $field === SelfConnectionSignalData::outboundModerationState
            || $field === SelfConnectionSignalData::fileUploadState
            || $field === SelfConnectionSignalData::fileUploadProgress
        ) {
            return $this->computeSelfConnectionField($field, $acceptKey);
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
     * Resolves who is behind an accept key from the chat runtime connection registry,
     * where the handshake records the acceptKey -> user mapping. Lets the ACCESS
     * browser guard and the page access gate identify the subscriber.
     *
     * A registry row is written for every connection the handshake sees, guest or
     * not, so no row means the row has not crossed the RT sync into this worker yet
     * rather than "nobody is there" - the frame waits instead of being refused as
     * anonymous (HIL-599). A demo that mounts no registry at all answers a settled nobody:
     * there is nothing to wait for.
     *
     * Fail-closed stops there, and that is a change of behavior (HIL-575). It belongs where
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
     * Answers the ADMIN page-level gate from the chat user storage: the durable
     * user row's admin flag — the same flag the admin ACCESS guard and the
     * setAdmin grant flow use. Runs in whatever worker serves the gated page
     * (the framework admin surface is served by the hilos index agent). A missing row
     * denies, as before; a read that failed no longer does (HIL-575). It answered 403 to an
     * administrator whose only problem was that this worker had not been declared a reader of
     * the collection, and it left nothing behind to say so.
     *
     * @param int $userId Authenticated durable user id
     * @return bool Whether this user may access ADMIN-level pages and actions
     */
    public function isAdmin(int $userId): bool
    {
        return (Hilos::$db->users[$userId] ?? null)?->admin === true;
    }

    /**
     * Computes runtime connection summary fields for user-shaped rows.
     *
     * @param string $field Summary field name
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

    /**
     * Computes current-connection fields for one subscribed accept key.
     *
     * @param string $field Self-connection field name
     * @param string $acceptKey Subscriber accept key
     * @return mixed Self-connection field value, or null when the connection is gone
     */
    private function computeSelfConnectionField(string $field, string $acceptKey): mixed
    {
        try {
            $connection = Hilos::$rt?->connections[$acceptKey] ?? null;
        } catch (RtCollectionNotFoundException) {
            return null;
        }

        if ($connection === null) {
            return null;
        }

        $payload = SelfConnectionBrowserPayload::forConnection($connection);

        return $payload[$field] ?? null;
    }
}
