<?php

declare(strict_types=1);

namespace Demo\Chat\Notification;

use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Hilos;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Utils\Logger;

/**
 * ChatMentionNotifier - raises the mention notification of the chat demo (HIL-557).
 *
 * Detection lives in the model rather than on a page: every feed message is born in
 * {@see EventsActions::addMessage()}, and both authors - the human from the main page
 * and a bot from the chat agent - pass through it, so a mention by a bot works through
 * this same code without a second copy.
 *
 * A name matches when the text carries "@" plus that display name case-insensitively
 * and the next character is a boundary; without the boundary a user named "a" would
 * collect every "@alex". Names are matched whole, so a name with spaces is mentioned
 * on the same terms as a one-word one. One recipient receives one notification however
 * many times the message names them, the author never notifies themselves, and blocked
 * or merged accounts are skipped - nobody would read a notification sent there.
 *
 * The emit is best-effort with respect to the domain action: the message is published
 * whatever happens to the notification, so a failure is logged, not raised.
 */
final class ChatMentionNotifier
{
    /** Agent id a failed emit is logged under. */
    private const string LOG_AGENT_ID = 'chat-mention-notifier';

    /** Longest message excerpt carried in the notification body, in characters. */
    private const int EXCERPT_LENGTH = 140;

    /** Appended to the excerpt when it cut the message short. */
    private const string EXCERPT_ELLIPSIS = '…';

    /** A character that continues a name, so a match ending on it is not a mention. */
    private const string WORD_CHARACTER_PATTERN = '/[\p{L}\p{N}_]/u';

    /** Author shown when the authoring user or bot row can no longer be resolved. */
    private const string UNKNOWN_AUTHOR_NAME = 'Someone';

    /**
     * Notifies every user the published message names, except its own author.
     *
     * Exactly one of `$authorUserId` or `$authorBotId` is expected from the caller.
     *
     * @param int $eventId Id of the published message event
     * @param string $message Published message text
     * @param ?int $authorUserId Authoring user id, or null when a bot wrote the message
     * @param ?int $authorBotId Authoring bot id, or null when a user wrote the message
     */
    public static function notifyMentions(
        int $eventId,
        string $message,
        ?int $authorUserId,
        ?int $authorBotId,
    ): void
    {
        try {
            self::notifyMentioned($eventId, $message, $authorUserId, $authorBotId);
        } catch (HilosException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Mention notification failed for eventId={$eventId}: {$e->getMessage()}",
            );
        }
    }

    /**
     * Emits one mention notification per named recipient.
     *
     * @param int $eventId Id of the published message event
     * @param string $message Published message text
     * @param ?int $authorUserId Authoring user id, or null when a bot wrote the message
     * @param ?int $authorBotId Authoring bot id, or null when a user wrote the message
     * @throws HilosException When reading the users or the emit fails
     */
    private static function notifyMentioned(
        int $eventId,
        string $message,
        ?int $authorUserId,
        ?int $authorBotId,
    ): void
    {
        $excerpt = self::excerpt($message);
        $title = self::authorName($authorUserId, $authorBotId) . ' mentioned you';

        foreach (Hilos::$db->users->listAll() as $user) {
            if (
                $user->id === null
                || $user->id === $authorUserId
                || $user->block
                || $user->mergedInto !== null
                || !self::isMentioned($message, $user->name)
            ) {
                continue;
            }

            Hilos::$notify?->emit(new NotificationDraft(
                userId: $user->id,
                type: ChatNotificationType::MENTION,
                title: $title,
                severity: NotificationSeverity::INFO,
                body: $excerpt,
                data: [
                    'eventId' => $eventId,
                    'authorUserId' => $authorUserId,
                    'authorBotId' => $authorBotId,
                    'excerpt' => $excerpt,
                ],
            ));
        }
    }

    /**
     * Whether the message names the given display name as a mention.
     *
     * @param string $message Published message text
     * @param string $name Display name to look for
     * @return bool True when "@name" occurs and the match ends on a boundary
     */
    private static function isMentioned(string $message, string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $needle = '@' . $name;
        $offset = 0;
        while (($position = mb_stripos($message, $needle, $offset)) !== false) {
            $next = mb_substr($message, $position + mb_strlen($needle), 1);
            if ($next === '' || preg_match(self::WORD_CHARACTER_PATTERN, $next) !== 1) {
                return true;
            }

            $offset = $position + 1;
        }

        return false;
    }

    /**
     * Shortens the message to the excerpt the notification body carries.
     *
     * @param string $message Published message text
     * @return string Message, cut to the excerpt length with a trailing ellipsis
     */
    private static function excerpt(string $message): string
    {
        if (mb_strlen($message) <= self::EXCERPT_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::EXCERPT_LENGTH) . self::EXCERPT_ELLIPSIS;
    }

    /**
     * Resolves the display name of the message author, user or bot.
     *
     * @param ?int $authorUserId Authoring user id, or null when a bot wrote the message
     * @param ?int $authorBotId Authoring bot id, or null when a user wrote the message
     * @return string Author display name, or a neutral stand-in when the row is gone
     * @throws HilosException When reading the author row fails
     */
    private static function authorName(?int $authorUserId, ?int $authorBotId): string
    {
        if ($authorUserId !== null) {
            return Hilos::$db->users[$authorUserId]?->name ?? self::UNKNOWN_AUTHOR_NAME;
        }

        if ($authorBotId !== null) {
            return Hilos::$db->bots[$authorBotId]?->name ?? self::UNKNOWN_AUTHOR_NAME;
        }

        return self::UNKNOWN_AUTHOR_NAME;
    }
}
