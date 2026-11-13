<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\HilosException;

/**
 * ChatAccountDataOwnerDirectory - whose the chat demo's own collections are.
 *
 * The chat agent, one for everybody, owns the feed: its events, the messages and attachments in
 * them, and the events that tell of a person's registration. The bots are nobody's personal data
 * and stay out of the erasure.
 */
final class ChatAccountDataOwnerDirectory extends AccountDataOwnerDirectory
{
    /**
     * The chat agent and the four feed collections.
     *
     * @return list<AccountDataOwner> The chat owner
     */
    protected static function owners(): array
    {
        return [
            new AccountDataOwner(
                'chat',
                [
                    ChatDbContext::events,
                    ChatDbContext::eventMessages,
                    ChatDbContext::eventUserRegistrations,
                    ChatDbContext::eventAttachments,
                ],
                AgentType::CHAT,
                false,
                self::hasChatRows(...),
            ),
        ];
    }

    /** @return array<string, AccountErasureExemption> The bots, which carry nothing personal */
    protected static function exempt(): array
    {
        return [ChatDbContext::bots => AccountErasureExemption::NotPersonal];
    }

    /**
     * Asks after the rows the erasure of the feed removes: the person's messages, the registration
     * event that names them, and the events of their renames.
     *
     * The rename events are seen through the journal only until the person's capture takes the
     * journal off them; after it the third check finds none, so the answer may turn false while
     * those events still stand, and they are seen through the capture ({@see ChatErasureRenameFeed})
     * (HIL-1940). The code of this method does not change for it.
     *
     * @param int $userId Account of the erasure circle
     * @return bool Whether the feed still holds rows of the person
     * @throws HilosException When a lookup fails
     */
    private static function hasChatRows(int $userId): bool
    {
        return Hilos::$db->eventMessages->eventIdsByAuthor($userId) !== []
            || Hilos::$db->eventUserRegistrations->byTarget($userId)->count() > 0
            || Hilos::$db->userRenames->eventIdsByUser($userId) !== [];
    }
}
