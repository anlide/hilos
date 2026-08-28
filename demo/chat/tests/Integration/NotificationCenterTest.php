<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\NotificationsLibraryAgent;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Schema\Schema;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Hilos;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;

/**
 * Proves the notification center works against the real activated tables (HIL-505).
 *
 * The three notification tables shipped as framework migration stubs and were never
 * copied into any demo, so every bell sync in every reference demo hit a missing
 * table. This drives the durable path end to end on the live database: emit writes a
 * row, the recipient's snapshot returns it with the right unread count, and the two
 * mark-read primitives clear it.
 *
 * The snapshot is asserted through the same collection pair the page's private
 * buildSnapshot() calls (listForUser + countUnreadForUser) rather than through the
 * page itself, which needs a live connection to resolve its recipient and to reply.
 * The socket-level path is e2e coverage and stays with HIL-490.
 *
 * The emit is driven on the library rather than on {@see Hilos::$notify} since HIL-771: the
 * facade became a door that queues a frame, so calling it here would assert nothing about a
 * row. What the case wants is the write, and the write is the library's.
 */
final class NotificationCenterTest extends IntegrationTestCase
{
    /** Synthetic recipient: hilos_notification has no FK to the project user table. */
    private const int RECIPIENT_ID = 909001;

    /** Second synthetic recipient, so mark-all-read cannot pass by touching foreign rows. */
    private const int OTHER_RECIPIENT_ID = 909002;

    /** @var ?NotificationsLibraryAgent Owner of the notification set, built on first use */
    private ?NotificationsLibraryAgent $library = null;

    protected function setUp(): void
    {
        parent::setUp();
        TruthSourceRegistry::register(HilosDbContext::notifications, true, 'test-agent');
        $this->deleteRecipientRows();
    }

    protected function tearDown(): void
    {
        $this->deleteRecipientRows();
        parent::tearDown();
    }

    public function testTheNotificationTableIsActivatedInThisDemo(): void
    {
        // The regression this ticket fixes: the table simply did not exist.
        self::assertNotNull(Schema::getTable(EntityNotification::_table));
    }

    public function testEmitWritesADurableRowTheSnapshotThenReturns(): void
    {
        $id = $this->library()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'Durable title',
            severity: NotificationSeverity::SUCCESS,
            body: 'Durable body',
            data: ['eventId' => 7],
        ));

        self::assertGreaterThan(0, $id);

        $collection = $this->collection();
        $recent = $collection->listForUser(self::RECIPIENT_ID, 20);

        self::assertCount(1, $recent);
        self::assertSame($id, $recent[0]->id);
        self::assertSame('demo.chat.test', $recent[0]->type);
        self::assertSame(NotificationSeverity::SUCCESS, $recent[0]->severity);
        self::assertSame('Durable title', $recent[0]->title);
        self::assertSame('Durable body', $recent[0]->body);
        self::assertSame(['eventId' => 7], $recent[0]->decodedData());
        self::assertTrue($recent[0]->isUnread());
        self::assertSame(1, $collection->countUnreadForUser(self::RECIPIENT_ID));
    }

    public function testMarkReadClearsTheRowAndTheUnreadCount(): void
    {
        $id = $this->library()->emit(new NotificationDraft(
            userId: self::RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'To be read',
        ));

        // Mark-read rides the item action on the view item, exactly as the page does.
        $view = Hilos::$db->notifications[$id] ?? null;

        self::assertNotNull($view);
        $view->actions->markRead();

        $collection = $this->collection();

        self::assertNotNull($view->readAt);
        self::assertSame(0, $collection->countUnreadForUser(self::RECIPIENT_ID));
        self::assertCount(1, $collection->listForUser(self::RECIPIENT_ID, 20));
    }

    public function testMarkAllReadClearsOnlyTheRecipientsOwnBatch(): void
    {
        foreach (['first', 'second', 'third'] as $title) {
            $this->library()->emit(new NotificationDraft(
                userId: self::RECIPIENT_ID,
                type: 'demo.chat.test',
                title: $title,
            ));
        }
        $this->library()->emit(new NotificationDraft(
            userId: self::OTHER_RECIPIENT_ID,
            type: 'demo.chat.test',
            title: 'someone else',
        ));

        $collection = $this->collection();

        self::assertSame(3, $collection->countUnreadForUser(self::RECIPIENT_ID));
        self::assertSame(3, $collection->markAllReadForUser(self::RECIPIENT_ID));
        self::assertSame(0, $collection->countUnreadForUser(self::RECIPIENT_ID));
        self::assertSame(1, $collection->countUnreadForUser(self::OTHER_RECIPIENT_ID));
    }

    /**
     * Builds the notifications library the emit happens in, once per case.
     *
     * Started on first use because {@see NotificationsLibraryAgent::onStart()} claims the four
     * notification collections, and a case writing any of them needs the claim.
     *
     * @return NotificationsLibraryAgent Library under test, started
     */
    private function library(): NotificationsLibraryAgent
    {
        if ($this->library === null) {
            $this->library = new NotificationsLibraryAgent();
            $this->library->onStart();
        }

        return $this->library;
    }

    /**
     * Resolves the framework-owned notifications collection from the facade.
     *
     * @return ObjectNotifications Notifications persistence primitives
     */
    private function collection(): ObjectNotifications
    {
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::notifications);
        self::assertInstanceOf(ObjectNotifications::class, $collection);

        return $collection;
    }

    /**
     * Drops both synthetic recipients' rows so each test starts from a known count.
     */
    private function deleteRecipientRows(): void
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int(self::RECIPIENT_ID));
        $params->add(SqlParam::int(self::OTHER_RECIPIENT_ID));
        Database::sql(
            'DELETE FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::user_id . '` IN (?, ?)',
            $params,
        );
    }
}
