<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\RenameModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Notification\NotificationSeverity;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Proves a moderation refusal reaches its author as a notification (HIL-557).
 *
 * The action error the page raises is a reply to the tab that acted; the notification
 * is the copy that survives, so the author still learns the verdict from another
 * device or after a reload. Only a verdict about the text notifies: an unavailable
 * moderator is an infrastructure failure and says nothing about what was written.
 */
final class ChatModerationNotificationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(HilosDbContext::notifications, true, self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        $this->deleteChatNotifications();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        $this->deleteChatNotifications();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        parent::tearDown();
    }

    public function testRejectedMessageNotifiesItsAuthor(): void
    {
        $user = Hilos::$db->users->actions->createWithName('ModerationAuthor557');
        Hilos::$rt->connections->actions->register('reject-notify-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['reject-notify-ak']?->actions->startOutboundModeration('blocked message');

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->dispatchTextModerationSignalToMainPage(
            new ChatAgent(),
            new ModerationResultSignalData(
                acceptKey: 'reject-notify-ak',
                userId: $user->id,
                message: 'blocked message',
                allow: false,
                reason: 'policy',
            ),
        );

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(ChatNotificationType::MESSAGE_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your message was not published', $notification->title);
        self::assertSame('Moderation rejected it: policy', $notification->body);
        self::assertSame(
            ['reason' => 'policy', 'message' => 'blocked message'],
            $notification->decodedData(),
        );
    }

    public function testAnUnavailableModeratorNotifiesNobodyAboutTheMessage(): void
    {
        $user = Hilos::$db->users->actions->createWithName('ModerationAuthor557');
        Hilos::$rt->connections->actions->register('unavailable-notify-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['unavailable-notify-ak']?->actions->startOutboundModeration('pending message');

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->dispatchTextModerationSignalToMainPage(
            new ChatAgent(),
            new ModerationResultSignalData(
                acceptKey: 'unavailable-notify-ak',
                userId: $user->id,
                message: 'pending message',
                allow: false,
                reason: 'service_unavailable',
            ),
        );

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections['unavailable-notify-ak']?->outboundModerationPhase,
        );
        self::assertCount(0, $this->notificationsFor($user->id));
    }

    public function testRejectedRenameNotifiesTheUserWhoAskedForIt(): void
    {
        $user = Hilos::$db->users->actions->createWithName('RenameAsker557');
        Hilos::$rt->connections->actions->register('rename-notify-ak', $user->id);
        Hilos::$rt->connections['rename-notify-ak']?->actions->startRenameModeration('BlockedName');

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->dispatchRenameModerationSignalToProfilePage(
            new ChatAgent(),
            new RenameModerationResultSignalData(
                acceptKey: 'rename-notify-ak',
                userId: (int)$user->id,
                newName: 'BlockedName',
                allow: false,
                reason: 'policy',
            ),
        );

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(ChatNotificationType::RENAME_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your new name was not accepted', $notification->title);
        self::assertSame('Moderation rejected it: policy', $notification->body);
        self::assertSame(
            ['reason' => 'policy', 'newName' => 'BlockedName'],
            $notification->decodedData(),
        );
    }

    public function testAnUnavailableModeratorNotifiesNobodyAboutTheName(): void
    {
        $user = Hilos::$db->users->actions->createWithName('RenameAsker557');
        Hilos::$rt->connections->actions->register('rename-unavailable-ak', $user->id);
        Hilos::$rt->connections['rename-unavailable-ak']?->actions->startRenameModeration('PendingName');

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->dispatchRenameModerationSignalToProfilePage(
            new ChatAgent(),
            new RenameModerationResultSignalData(
                acceptKey: 'rename-unavailable-ak',
                userId: (int)$user->id,
                newName: 'PendingName',
                allow: false,
                reason: 'service_unavailable',
            ),
        );

        self::assertSame(
            ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections['rename-unavailable-ak']?->renameModerationPhase,
        );
        self::assertCount(0, $this->notificationsFor($user->id));
    }

    /**
     * Routes an outbound-moderation verdict to the main page, as the moderator agent does.
     *
     * @param ChatAgent $agent Agent the page is built on
     * @param ModerationResultSignalData $result Verdict to deliver
     */
    private function dispatchTextModerationSignalToMainPage(
        ChatAgent $agent,
        ModerationResultSignalData $result,
    ): void
    {
        $this->dispatch(
            $agent,
            new AgentSignalData($result),
            ChatSignalConstants::MODERATION_RESULT,
            MainPage::PAGE,
        );
    }

    /**
     * Routes a rename verdict to the profile page, as the moderator agent does.
     *
     * @param ChatAgent $agent Agent the page is built on
     * @param RenameModerationResultSignalData $result Verdict to deliver
     */
    private function dispatchRenameModerationSignalToProfilePage(
        ChatAgent $agent,
        RenameModerationResultSignalData $result,
    ): void
    {
        $this->dispatch(
            $agent,
            new AgentSignalData($result),
            ChatSignalConstants::RENAME_MODERATION_RESULT,
            ProfilePage::PAGE,
        );
    }

    /**
     * Dispatches one agent signal through a router that knows only the page under test.
     *
     * The page raises a validation exception on every rejection, which the router turns
     * into the action error the acting tab receives; these cases are about what the
     * notification center holds once that has happened.
     *
     * @param ChatAgent $agent Agent the page is built on
     * @param AgentSignalData $signal Signal envelope carrying the verdict
     * @param string $signalName Agent signal name to route
     * @param string $page Page key the signal is routed to
     */
    private function dispatch(
        ChatAgent $agent,
        AgentSignalData $signal,
        string $signalName,
        string $page,
    ): void
    {
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(),
            new SignalRouteConfig([
                SignalTypeConstants::AGENT_SIGNAL => [$signalName => $page],
            ]),
        );

        ExecutionContext::setCurrentAcceptKey($signal->getAcceptKey());
        try {
            $router->dispatchAgentSignal($signal, '', $signalName);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    /**
     * Asserts the recipient received exactly one notification and returns it.
     *
     * @param ?int $userId Recipient user id
     * @return ObjectNotification The recipient's only notification
     */
    private function onlyNotificationFor(?int $userId): ObjectNotification
    {
        $notifications = $this->notificationsFor($userId);
        self::assertCount(1, $notifications);

        return $notifications[0];
    }

    /**
     * Lists the notifications currently held for the recipient.
     *
     * @param ?int $userId Recipient user id
     * @return list<ObjectNotification> Recipient notifications, newest first
     */
    private function notificationsFor(?int $userId): array
    {
        self::assertNotNull($userId);
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notifications);
        self::assertInstanceOf(ObjectNotifications::class, $collection);

        return $collection->listForUser($userId, 20);
    }

    /**
     * Drops every notification this demo raises, so each case starts from a known count.
     */
    private function deleteChatNotifications(): void
    {
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::type . '` LIKE \'chat.%\'',
        );
    }
}
