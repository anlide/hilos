<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Hilos;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * Chat demo browser-facing context.
 *
 * Supplies the chat-specific computed browser fields — the self-connection state —
 * over the framework source fan-out. A person's presence and session count, the
 * password fields and the scheduled deletion's date are the framework's, and the chat
 * lists that name them are served by the same framework branches (HIL-1254). Settings
 * rows are delivered by the framework self-snapshot path (HilosSettingsTable), not here.
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
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
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
            $field === SelfConnectionSignalData::messageRateLimitSecondsRemaining
            || $field === SelfConnectionSignalData::outboundModerationState
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
