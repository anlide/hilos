<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\DTO\UserAccountDeletionCancelDoneSignalData;
use Hilos\Users\DTO\UserAccountDeletionCancelSignalData;
use Hilos\Users\DTO\UserPhotoPutDoneSignalData;
use Hilos\Users\DTO\UserPhotoPutSignalData;
use Hilos\Users\DTO\UserPhotoRemoveDoneSignalData;
use Hilos\Users\DTO\UserPhotoRemoveSignalData;

/**
 * A standing deletion and the profile photo are written by the person's agent (HIL-1409).
 *
 * The users library still opens the request. Canceling it, and writing or removing the photo,
 * are refused when the library does them itself: it no longer holds those operations. The same
 * writes handed to the person's agent go through. A frame naming another person is refused.
 *
 * Every writer runs in its own frame and under the claims its own class declares, and the frames
 * between them are carried by the case.
 */
final class PersonDeletionPhotoEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-deletion-photo-edits';

    /** Registry file the photo cases set. */
    private const int FILE_ID = 14091;

    private DeletionPhotoTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::photoStubs(down: true);
        self::photoStubs(down: false);

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new DeletionPhotoTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->users->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->users->getId()));
        Hilos::$sr = $this->previousRouter;
        SourceChangeBus::reset();
        self::photoStubs(down: true);

        parent::tearDown();
    }

    /**
     * The library may open a request. Canceling it, or writing or removing a photo, is refused.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryCancelingOrWritingAPhotoItselfIsRefused(): void
    {
        $userId = $this->seedPerson();
        $at = date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY);
        $request = $this->inLibrary(
            fn () => Hilos::$db->accountDeletions->actions->request($userId, $at),
        );
        self::assertNotNull(Hilos::$db->accountDeletions->liveOf($userId));

        try {
            $this->inLibrary(fn () => $request->actions->cancel());
            self::fail('The users library must not cancel a request past the person\'s agent');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->users->getId(), $refusal->getMessage());
        }
        self::assertNotNull(Hilos::$db->accountDeletions->liveOf($userId));

        // The person's agent holds the photo claim, so the refusal names this library rather than an empty table.
        $this->personAgent($userId);
        try {
            $this->inLibrary(fn () => Hilos::$db->userPhotos->actions->put($userId, self::FILE_ID));
            self::fail('The users library must not write a photo past the person\'s agent');
        } catch (CreateNotAllowedException | WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->users->getId(), $refusal->getMessage());
        }
        try {
            $this->inLibrary(fn () => Hilos::$db->userPhotos->actions->deleteForUser($userId));
            self::fail('The users library must not remove a photo past the person\'s agent');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->users->getId(), $refusal->getMessage());
        }
        self::assertNull(Hilos::$db->userPhotos[$userId]);
    }

    /**
     * The person's agent cancels the request the library opened, then sets and removes the photo.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testThePersonsAgentCancelsAndWritesThePhoto(): void
    {
        $userId = $this->seedPerson();
        $this->seedFile($userId);
        $at = date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY);
        $this->inLibrary(fn () => Hilos::$db->accountDeletions->actions->request($userId, $at));

        $canceled = $this->carry(
            HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL,
            $this->cancelAsk($userId),
        );
        self::assertInstanceOf(UserAccountDeletionCancelDoneSignalData::class, $canceled);
        self::assertNull($canceled->error);
        self::assertTrue($canceled->canceled);
        self::assertNull(Hilos::$db->accountDeletions->liveOf($userId));

        $put = $this->carry(HilosSignalConstants::HILOS_USER_PHOTO_PUT, new UserPhotoPutSignalData(
            userId: $userId,
            fileId: self::FILE_ID,
            replySignal: HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: null,
            action: HilosSignalConstants::PROFILE_PHOTO_SET,
            successMessage: null,
        ));
        self::assertInstanceOf(UserPhotoPutDoneSignalData::class, $put);
        self::assertNull($put->error);
        self::assertSame(self::FILE_ID, Hilos::$db->userPhotos[$userId]?->fileId);

        $removed = $this->carry(HilosSignalConstants::HILOS_USER_PHOTO_REMOVE, new UserPhotoRemoveSignalData(
            userId: $userId,
            replySignal: HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::PROFILE_PHOTO_REMOVE,
            successMessage: null,
        ));
        self::assertInstanceOf(UserPhotoRemoveDoneSignalData::class, $removed);
        self::assertNull($removed->error);
        self::assertSame(self::FILE_ID, $removed->fileId);
        self::assertNull(Hilos::$db->userPhotos[$userId]);
    }

    /**
     * A frame naming another person is refused rather than written.
     *
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = $this->seedPerson();
        $otherId = $this->seedPerson();
        $at = date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY);
        $this->inLibrary(fn () => Hilos::$db->accountDeletions->actions->request($otherId, $at));

        $this->expectException(AgentException::class);
        try {
            $this->asPerson($userId, fn ($agent) => $agent->onSignalAgent(
                new AgentSignalData(data: $this->cancelAsk($otherId)),
                '',
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL,
            ));
        } finally {
            self::assertNotNull(Hilos::$db->accountDeletions->liveOf($otherId));
        }
    }

    /**
     * Sends one frame the way the library does, hands it to the person's agent and takes its answer.
     *
     * @param string $name Agent-signal name the frame travels under
     * @param UserAccountDeletionCancelSignalData|UserPhotoPutSignalData|UserPhotoRemoveSignalData $frame The frame
     * @return mixed Payload of the answer, under the reply name the frame carries
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(
        string $name,
        UserAccountDeletionCancelSignalData|UserPhotoPutSignalData|UserPhotoRemoveSignalData $frame,
    ): mixed {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->users->sendToAgent($name, $frame);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $answer = null;
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer === null && $queued->signalName->getName() === $frame->replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answer = $queued->data->data;
            }
        }
        self::assertNotNull($answer, "Nothing was sent under {$frame->replySignal}");

        return $answer;
    }

    /**
     * @param int $userId Person whose request would be canceled
     * @return UserAccountDeletionCancelSignalData The ask the users library sends
     */
    private function cancelAsk(int $userId): UserAccountDeletionCancelSignalData
    {
        return new UserAccountDeletionCancelSignalData(
            userId: $userId,
            by: null,
            replySignal: HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL,
            successMessage: null,
            answerSignal: null,
        );
    }

    /**
     * Runs one step in the library's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the library
     * @return T Whatever the step returns
     */
    private function inLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), $step);
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Owner of the file
     * @throws DatabaseException When the insert fails
     */
    private function seedFile(int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file` (`id`, `stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
            . '`owner_user_id`, `visibility`, `bound`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::FILE_ID, 'photo-14091.jpg', 'photo.jpg', 'image/jpeg', 4, str_repeat('a', 64), $userId, 'public', 0],
        );
    }

    /**
     * @param bool $down Drop the photo tables instead of creating them
     * @throws DatabaseException When a stub statement fails
     */
    private static function photoStubs(bool $down): void
    {
        $tables = $down ? ['hilos_user_photo', 'hilos_file'] : ['hilos_file', 'hilos_user_photo'];
        // external-boundary: the create stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class DeletionPhotoTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_deletion_photo_users_library';
}
