<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Demo\Chat\Database\Entity\Item\EventAttachment as EntityEventAttachment;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Demo\Chat\Database\Entity\Item\EventUserRename as EntityEventUserRename;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The chat's half of an account erasure (HIL-302), run by the session holder's sweep.
 *
 * What a chat keeps of a person goes: the messages they wrote with the attachments, the events
 * of those messages, the registration and rename events about them, and their row. A rename
 * they made of somebody else stays and loses only its actor. The attachment files are removed
 * after the commit. Another person's rows are the proof that the erasure cut by the person.
 *
 * Driven inside the library's own execution frame, as {@see AccountMergeTest} drives the
 * merge: every write runs as the agent that owns the sessions, so the borrowed claims the
 * erasure needs on the chat tables are asked exactly as on a live node.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountErasureTest extends IntegrationTestCase
{
    /** A moment long gone, so the request is due on the first tick. */
    private const string PAST = '2026-01-01 00:00:00';

    /**
     * Gives the case a signal router, which the sign-outs and the notifications frame are queued on.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

    /**
     * The person's chat rows and file go, the other person's stay.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testTheErasureDeletesWhatAChatKeepsOfThePerson(): void
    {
        $personId = (int)Hilos::$db->users->actions->createWithName('Leaving')->id;
        $otherId = (int)Hilos::$db->users->actions->createWithName('Staying')->id;
        Hilos::$db->identities->createMagicLinkIdentity($personId, 'leaving-' . RandomHelper::hex(6) . '@example.test');

        $personRegistration = (int)Hilos::$db->events->actions->addUserRegistered($personId)->id;
        $otherRegistration = (int)Hilos::$db->events->actions->addUserRegistered($otherId)->id;
        $personRename = (int)Hilos::$db->events->actions->addUserRenamed($personId, 'Arriving', 'Leaving')->id;
        $renameOfOther = (int)Hilos::$db->events->actions->addUserRenamedByAdmin($otherId, 'Old', 'Staying', $personId)->id;
        $storedName = 'erasure-' . RandomHelper::hex(8) . '.txt';
        $fs = Hilos::$fs;
        self::assertNotNull($fs);
        $fs->files->create($storedName)->append('attachment of a person who asked to leave');
        $personMessage = (int)Hilos::$db->events->actions->addMessage(
            'goodbye',
            userId: $personId,
            attachments: new PublishedAttachmentInputs(new PublishedAttachmentInput('note.txt', 'text/plain', $storedName)),
        )->id;
        $otherMessage = (int)Hilos::$db->events->actions->addMessage('stay', userId: $otherId)->id;
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [$personId, self::PAST, self::PAST],
        );

        $this->runErasure();

        self::assertCount(0, EntityUser::get([EntityUser::id => $personId]), 'The person row is gone');
        self::assertCount(1, EntityUser::get([EntityUser::id => $otherId]));
        self::assertCount(0, EntityIdentity::get([EntityIdentity::user_id => $personId]), 'The ways in are gone');
        self::assertCount(0, EntityEventMessage::get([EntityEventMessage::author_user_id => $personId]));
        self::assertCount(0, EntityEventAttachment::get([EntityEventAttachment::event_id => $personMessage]));
        self::assertCount(0, EntityEventUserRegistration::get([EntityEventUserRegistration::target_user_id => $personId]));
        self::assertCount(0, EntityEventUserRename::get([EntityEventUserRename::target_user_id => $personId]));
        foreach ([$personRegistration, $personRename, $personMessage] as $eventId) {
            self::assertCount(0, EntityEvent::get([EntityEvent::id => $eventId]), "Event {$eventId} about the person is gone");
        }
        foreach ([$otherRegistration, $renameOfOther, $otherMessage] as $eventId) {
            self::assertCount(1, EntityEvent::get([EntityEvent::id => $eventId]), "Event {$eventId} of the other person stays");
        }
        $renameLeft = EntityEventUserRename::get([EntityEventUserRename::event_id => $renameOfOther])->first();
        self::assertNotNull($renameLeft);
        self::assertSame($otherId, $renameLeft->target_user_id);
        self::assertNull($renameLeft->actor_user_id, 'The rename of the other person lost its actor');
        self::assertFalse($fs->files[$storedName]->exists(), 'The attachment file is removed after the commit');

        Database::sql('SELECT `completed_at` FROM `hilos_account_deletion` WHERE `user_id` = ?', [$personId]);
        self::assertNotNull(Database::row()['completed_at'] ?? null, 'The request stays behind, carried out');
    }

    /**
     * Arms and runs the sessions library's tick inside its own execution frame.
     *
     * @throws HilosException When the tick fails
     */
    private function runErasure(): void
    {
        $library = new SessionsLibraryAgent();
        $this->startAgent($library);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library): void {
                $library->onTick();
            },
        );
    }
}
