<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Demo\Chat\Database\Entity\Item\EventAttachment as EntityEventAttachment;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The chat's half of an account erasure (HIL-302), run by the session holder's sweep.
 *
 * What a chat keeps of a person goes: the messages they wrote with the attachments, the events
 * of those messages, the registration events and the rename journal rows about them with their
 * feed events, and their row. A rename they made of somebody else stays with its feed line and
 * loses only its author. The attachments' registry files are named to the files library after
 * the commit, which removes them (HIL-144). Another person's rows are the proof that the erasure
 * cut by the person.
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
        $personRename = (int)Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($personId, $personId, 'Arriving', 'Leaving'),
        )->id;
        $renameOfOther = (int)Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($otherId, $personId, 'Old', 'Staying'),
        )->id;
        $file = $this->keepRegistryFile($personId, 'note.txt', 'attachment of a person who asked to leave');
        $fileId = (int)$file->id;
        $personMessage = (int)Hilos::$db->events->actions->addMessage('goodbye', userId: $personId, fileIds: [$fileId])->id;
        $otherMessage = (int)Hilos::$db->events->actions->addMessage('stay', userId: $otherId)->id;
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [$personId, self::PAST, self::PAST],
        );

        $this->drainSignals();
        $this->runErasure();
        $removals = $this->drainFileRemovals();

        self::assertCount(0, EntityUser::get([EntityUser::id => $personId]), 'The person row is gone');
        self::assertCount(1, EntityUser::get([EntityUser::id => $otherId]));
        self::assertCount(0, EntityIdentity::get([EntityIdentity::user_id => $personId]), 'The ways in are gone');
        self::assertCount(0, EntityEventMessage::get([EntityEventMessage::author_user_id => $personId]));
        self::assertCount(0, EntityEventAttachment::get([EntityEventAttachment::event_id => $personMessage]));
        self::assertCount(0, EntityEventUserRegistration::get([EntityEventUserRegistration::target_user_id => $personId]));
        self::assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $personId]));
        foreach ([$personRegistration, $personRename, $personMessage] as $eventId) {
            self::assertCount(0, EntityEvent::get([EntityEvent::id => $eventId]), "Event {$eventId} about the person is gone");
        }
        foreach ([$otherRegistration, $renameOfOther, $otherMessage] as $eventId) {
            self::assertCount(1, EntityEvent::get([EntityEvent::id => $eventId]), "Event {$eventId} of the other person stays");
        }
        $renameLeft = EntityUserRename::get([EntityUserRename::event_id => $renameOfOther])->first();
        self::assertNotNull($renameLeft);
        self::assertSame($otherId, $renameLeft->user_id);
        self::assertNull($renameLeft->renamed_by_user_id, 'The rename of the other person lost its author');
        self::assertSame([[$fileId]], $removals, 'The attachment file is named to the files library after the commit');
        $storedName = $file->storedName;
        Hilos::$db->files[$fileId]?->actions->delete();
        Hilos::$fs?->files[$storedName]->unlink();

        Database::sql('SELECT `completed_at` FROM `hilos_account_deletion` WHERE `user_id` = ?', [$personId]);
        self::assertNotNull(Database::row()['completed_at'] ?? null, 'The request stays behind, carried out');
    }

    /**
     * An account folded into another one is erased whole - its deletion was asked for before the
     * merge - and the framework takes its merge row before it deletes the person's row,
     * which the merge row would hold (HIL-1199).
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasingAFoldedAccountTakesItsMergeRow(): void
    {
        $foldedId = (int)Hilos::$db->users->actions->createWithName('Folded and leaving')->id;
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor')->id;
        Hilos::$db->userMerges->actions->add($foldedId, $survivorId);
        Hilos::$db->users[$foldedId]->actions->setBlock(true);
        $this->requestDueDeletion($foldedId);

        $this->runErasure();

        self::assertCount(0, EntityUser::get([EntityUser::id => $foldedId]), 'The folded account is gone');
        self::assertCount(0, EntityUserMerge::get([EntityUserMerge::user_id => $foldedId]), 'Its merge row is gone');
        self::assertCount(1, EntityUser::get([EntityUser::id => $survivorId]));
    }

    /**
     * Erasing the survivor also erases its folded account and the folded account's feed events.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasingTheSurvivorErasesTheFoldedAccount(): void
    {
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor and leaving')->id;
        $foldedId = (int)Hilos::$db->users->actions->createWithName('Folded')->id;
        Hilos::$db->userMerges->actions->add($foldedId, $survivorId);
        Hilos::$db->users[$foldedId]->actions->setBlock(true);
        $registrationId = (int)Hilos::$db->events->actions->addUserRegistered($foldedId)->id;
        $renameId = (int)Hilos::$db->events->actions->addUserRenamed(
            Hilos::$db->userRenames->actions->add($foldedId, $foldedId, 'Before', 'Folded'),
        )->id;
        $this->requestDueDeletion($survivorId);

        $this->runErasure();

        self::assertCount(0, EntityUser::get([EntityUser::id => $survivorId]), 'The survivor is gone');
        self::assertCount(0, EntityUser::get([EntityUser::id => $foldedId]), 'The folded account is gone');
        self::assertCount(0, EntityUserMerge::get([EntityUserMerge::user_id => $foldedId]));
        self::assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $foldedId]));
        self::assertCount(0, EntityEvent::get([EntityEvent::id => $registrationId]));
        self::assertCount(0, EntityEvent::get([EntityEvent::id => $renameId]));
    }

    /**
     * Drains the queue and returns the file lists of every remove frame on it.
     *
     * @return list<list<int>> Registry ids named by each hilos_file_remove frame, in queue order
     */
    private function drainFileRemovals(): array
    {
        $removals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_FILE_REMOVE) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
            $removals[] = $signal->data->data->fileIds;
        }

        return $removals;
    }

    /**
     * @param int $userId Person whose deletion is due at once
     * @throws HilosException When the request cannot be written
     */
    private function requestDueDeletion(int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [$userId, self::PAST, self::PAST],
        );
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
