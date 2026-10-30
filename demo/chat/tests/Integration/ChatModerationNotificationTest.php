<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\UserNotificationType;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Proves a moderation refusal reaches its author as a notification (HIL-557).
 *
 * The composer retains a refusal for the tab that acted; the notification is its
 * durable copy, so the author still learns the verdict from another
 * device or after a reload. Only a verdict about the content notifies: an unavailable
 * moderator, or a picture that cannot be checked, says nothing about what was sent.
 */
final class ChatModerationNotificationTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string TEST_AGENT_ID = 'test-agent';

    /** Registers chat fixture ownership and starts with no notifications or connections. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->claimChatChecks();
        TruthSourceRegistry::register(HilosDbContext::notifications, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $this->deleteChatNotifications();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
    }

    /** Removes chat fixture rows; the base releases the name protocol owners. */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        $this->forgetChatChecks();
        $this->deleteChatNotifications();
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
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
        Hilos::$rt->connections['reject-notify-ak']?->actions->startOutboundModeration('blocked message', []);

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->deliverMessageDecision(new ChatAgent(), 'reject-notify-ak', false, 'insult');

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(ChatNotificationType::MESSAGE_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your message was not published', $notification->title);
        self::assertSame('Message rejected: it appears to contain an insult.', $notification->body);
        self::assertSame(
            ['reason' => 'Message rejected: it appears to contain an insult.', 'message' => 'blocked message'],
            $notification->decodedData(),
        );
    }

    public function testRejectedMessageWithCategoryNotifiesAuthorWithCategoryPhrase(): void
    {
        $user = Hilos::$db->users->actions->createWithName('SpamAuthor557');
        Hilos::$rt->connections->actions->register('reject-spam-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['reject-spam-ak']?->actions->startOutboundModeration('spam message', []);

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->deliverMessageDecision(new ChatAgent(), 'reject-spam-ak', false, 'spam');

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(ChatNotificationType::MESSAGE_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your message was not published', $notification->title);
        self::assertSame('Message rejected: it appears to be spam.', $notification->body);
        self::assertSame(
            ['reason' => 'Message rejected: it appears to be spam.', 'message' => 'spam message'],
            $notification->decodedData(),
        );
    }

    public function testAnUnavailableModeratorNotifiesNobodyAboutTheMessage(): void
    {
        $user = Hilos::$db->users->actions->createWithName('ModerationAuthor557');
        Hilos::$rt->connections->actions->register('unavailable-notify-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['unavailable-notify-ak']?->actions->startOutboundModeration('pending message', []);

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->deliverMessageDecision(new ChatAgent(), 'unavailable-notify-ak', false, 'service_unavailable');

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections['unavailable-notify-ak']?->outboundModerationPhase,
        );
        self::assertCount(0, $this->notificationsFor($user->id));
    }

    public function testARejectedPictureNotifiesItsAuthorWithThePicturePhrase(): void
    {
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $user = Hilos::$db->users->actions->createWithName('PictureAuthor557');
        $this->completeUpload('picture-notify-ak', (int)$user->id, 'doc-1', 'notes.pdf', 'application/pdf', 'pdf');
        $this->completeUpload('picture-notify-ak', (int)$user->id, 'pic-1', 'holiday.png', 'image/png', 'png');
        Hilos::$rt->connections->actions->register('picture-notify-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['picture-notify-ak']?->actions->startOutboundModeration(
            'hello',
            ['doc-1', 'pic-1'],
        );

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->deliverMessageDecision(new ChatAgent(), 'picture-notify-ak', false, 'nudity', 'pic-1');

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(ChatNotificationType::MESSAGE_REJECTED, $notification->type);
        self::assertSame(
            'Message rejected: attachment 2 (holiday.png) looks like nudity or sexual content.',
            $notification->body,
        );
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
    }

    public function testAnUnreadablePictureNotifiesNobody(): void
    {
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        $user = Hilos::$db->users->actions->createWithName('PictureAuthor557');
        $this->completeUpload('picture-unreadable-notify-ak', (int)$user->id, 'pic-1', 'broken.png', 'image/png', 'png');
        Hilos::$rt->connections->actions->register('picture-unreadable-notify-ak', $user->id);
        Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
        Hilos::$rt->connections['picture-unreadable-notify-ak']?->actions->startOutboundModeration('hello', ['pic-1']);

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->deliverMessageDecision(new ChatAgent(), 'picture-unreadable-notify-ak', false, 'unreadable', 'pic-1');

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED,
            Hilos::$rt->connections['picture-unreadable-notify-ak']?->outboundModerationPhase,
        );
        self::assertCount(0, $this->notificationsFor($user->id));
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
    }

    /** A real accepted name check reports its content refusal to its person. */
    public function testRejectedRenameNotifiesTheUserWhoAskedForIt(): void
    {
        $user = Hilos::$db->users->actions->createWithName('RenameAsker557');
        Hilos::initSignalRouter(new ChatSignalRouter());
        $oldName = $user->name;
        $check = $this->beginProfileNameCheck((int)$user->id, 'rename-notify-ak', 'BlockedName');
        $this->dispatchRenameModerationVerdict(
            new ModerationVerdictSignalData(
                acceptKey: 'rename-notify-ak',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: $check->requestId,
                allow: false,
                reason: 'spam',
            ),
        );
        $this->assertProfileNamePhase('rename-notify-ak', StateHilosProfileNameChange::PHASE_REFUSED);
        self::assertSame($oldName, Hilos::$db->users[$user->id]->name);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find('rename-notify-ak', ModerationKindKey::PROFILE_NAME));
        self::assertCount(0, Hilos::$db->events);

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(UserNotificationType::NAME_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your new name was not accepted', $notification->title);
        self::assertSame('This name was not accepted because it appears to be spam.', $notification->body);
        self::assertSame(
            ['reason' => 'This name was not accepted because it appears to be spam.', 'newName' => 'BlockedName'],
            $notification->decodedData(),
        );
    }

    /** The category sentence survives the accepted name protocol unchanged. */
    public function testRejectedRenameWithCategoryNotifiesUserWithCategoryPhrase(): void
    {
        $user = Hilos::$db->users->actions->createWithName('ImpersonateAsker557');
        Hilos::initSignalRouter(new ChatSignalRouter());
        $oldName = $user->name;
        $check = $this->beginProfileNameCheck((int)$user->id, 'rename-cat-ak', 'ImpersonateName');
        $this->dispatchRenameModerationVerdict(
            new ModerationVerdictSignalData(
                acceptKey: 'rename-cat-ak',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: $check->requestId,
                allow: false,
                reason: 'impersonation',
            ),
        );
        $this->assertProfileNamePhase('rename-cat-ak', StateHilosProfileNameChange::PHASE_REFUSED);
        self::assertSame($oldName, Hilos::$db->users[$user->id]->name);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find('rename-cat-ak', ModerationKindKey::PROFILE_NAME));
        self::assertCount(0, Hilos::$db->events);

        $notification = $this->onlyNotificationFor($user->id);

        self::assertSame(UserNotificationType::NAME_REJECTED, $notification->type);
        self::assertSame(NotificationSeverity::WARNING, $notification->severity);
        self::assertSame('Your new name was not accepted', $notification->title);
        self::assertSame(
            'This name was not accepted because it appears to impersonate someone else.',
            $notification->body,
        );
        self::assertSame(
            [
                'reason' => 'This name was not accepted because it appears to impersonate someone else.',
                'newName' => 'ImpersonateName',
            ],
            $notification->decodedData(),
        );
    }

    /** An accepted check ends refused on unavailability without notifying or renaming. */
    public function testAnUnavailableModeratorNotifiesNobodyAboutTheName(): void
    {
        $user = Hilos::$db->users->actions->createWithName('RenameAsker557');
        Hilos::initSignalRouter(new ChatSignalRouter());
        $oldName = $user->name;
        $check = $this->beginProfileNameCheck((int)$user->id, 'rename-unavailable-ak', 'PendingName');
        $this->dispatchRenameModerationVerdict(
            new ModerationVerdictSignalData(
                acceptKey: 'rename-unavailable-ak',
                kind: ModerationKindKey::PROFILE_NAME,
                requestId: $check->requestId,
                allow: false,
                reason: 'service_unavailable',
            ),
        );
        $this->assertProfileNamePhase('rename-unavailable-ak', StateHilosProfileNameChange::PHASE_REFUSED);
        self::assertSame($oldName, Hilos::$db->users[$user->id]->name);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find('rename-unavailable-ak', ModerationKindKey::PROFILE_NAME));
        self::assertCount(0, Hilos::$db->events);
        self::assertCount(0, $this->notificationsFor($user->id));
    }

    /**
     * Hands a rename verdict to the users library, as the moderator agent does (HIL-771).
     *
     * The verdict stopped being a page's to take when the rename it answers moved onto the
     * library that owns the account: applying it writes the row, and refusing it sends the
     * acting tab the same action error the page used to raise.
     *
     * @param ModerationVerdictSignalData $result Verdict to deliver
     * @throws HilosException When the verdict cannot be applied
     */
    private function dispatchRenameModerationVerdict(ModerationVerdictSignalData $result): void
    {
        $signal = new AgentSignalData($result);
        ExecutionContext::setCurrentAcceptKey($signal->getAcceptKey());
        try {
            $library = $this->usersLibrary();
            $this->underAgent($library, static fn () => $library->onSignalAgent($signal, '', HilosSignalConstants::HILOS_MODERATION_VERDICT));
            self::assertSame(0, $this->deliverPersonAgentFrames(), 'A refused check sends no owner ask');
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    /**
     * Asserts the recipient received exactly one notification and returns it.
     *
     * @param ?int $userId Recipient user id
     * @return ObjectNotification The recipient's only notification
     * @throws HilosException When an emitted notification cannot be written
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
     * Runs the pending emits first: a refusal no longer writes a row where it is decided, it
     * queues a frame to the notifications library that owns the table (HIL-771). Done here
     * rather than in each case so that a case asserting nobody was notified proves it against a
     * library that was given every chance to write.
     *
     * @param ?int $userId Recipient user id
     * @return list<ObjectNotification> Recipient notifications, newest first
     * @throws HilosException When an emitted notification cannot be written
     */
    private function notificationsFor(?int $userId): array
    {
        $this->deliverNotificationFrames();
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
            . ' WHERE `' . EntityNotification::type . '` LIKE \'chat.%\' OR `' . EntityNotification::type . '` = ?',
            [UserNotificationType::NAME_REJECTED],
        );
    }
}
