<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\UserNotificationType;

/**
 * Renaming a person as the framework does it: the users library asks, the person's agent writes,
 * the library tells the news (HIL-1195, HIL-1404).
 *
 * The library under test is the base class with only its hook overridden to watch it, and the
 * agent is the base class with nothing overridden, so what answers is the framework's own body
 * over `hilos_user` and `hilos_user_rename`. Each runs in its own frame and under the claims its
 * own class declares, and the frames between them are carried by the case, which is what proves
 * the agent holds the name and its journal row and the library only what follows.
 */
final class UsersLibraryRenameIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int MISSING_USER_ID = 9999;

    /** Accept key standing in for the admin's browser. */
    private const string ACCEPT_KEY = 'accept-1';

    private UsersLibraryRenameTestLibrary $library;

    private ?SignalRouter $previousRouter = null;

    private ?HilosNotifier $previousNotify = null;

    /** @var list<SignalDTO> Frames the library and the agents sent anywhere but to each other */
    private array $outbox = [];

    /** How many rename asks this case carried to a person's agent. */
    private int $hopsToThePerson = 0;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        $this->previousNotify = Hilos::$notify;
        Hilos::$sr = new SignalRouter();
        Hilos::$notify = new HilosNotifier();

        $this->library = new UsersLibraryRenameTestLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        Hilos::$sr = $this->previousRouter;
        Hilos::$notify = $this->previousNotify;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the rename fails
     */
    public function testAnAdministratorRenamesSomebodyElse(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);

        $renameId = $this->rename($userId, '  Grace  ', $adminId);

        self::assertNotNull($renameId);
        self::assertSame('Grace', self::nameOf($userId));
        self::assertSame(
            [['user_id' => $userId, 'renamed_by_user_id' => $adminId, 'old_name' => 'Ada', 'new_name' => 'Grace']],
            self::journal(),
        );
        self::assertSame($renameId, self::journalIds()[0]);

        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertSame($userId, $notices[0]->userId);
        self::assertSame(UserNotificationType::RENAMED, $notices[0]->type);
        self::assertSame('An administrator renamed your account', $notices[0]->title);
        self::assertSame('Your name is now Grace', $notices[0]->body);
        self::assertSame(['oldName' => 'Ada', 'newName' => 'Grace', 'actorUserId' => $adminId], $notices[0]->data);

        self::assertCount(1, $this->library->renamed);
        self::assertSame($renameId, (int)$this->library->renamed[0]->id);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the rename fails
     */
    public function testAPersonRenamedByThemselvesIsTheAuthorAndIsNotTold(): void
    {
        $adminId = self::seedPerson('Root', admin: true);

        $this->rename($adminId, 'Groot', $adminId);

        self::assertSame(
            [['user_id' => $adminId, 'renamed_by_user_id' => $adminId, 'old_name' => 'Root', 'new_name' => 'Groot']],
            self::journal(),
        );
        self::assertSame([], $this->notices());
        self::assertCount(1, $this->library->renamed);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the rename fails
     */
    public function testARenameNoPersonDidHasNoAuthorAndIsTold(): void
    {
        $userId = self::seedPerson('Ada', admin: false);

        $this->rename($userId, 'Grace', null);

        self::assertSame(
            [['user_id' => $userId, 'renamed_by_user_id' => null, 'old_name' => 'Ada', 'new_name' => 'Grace']],
            self::journal(),
        );
        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertSame(['oldName' => 'Ada', 'newName' => 'Grace', 'actorUserId' => null], $notices[0]->data);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the rename fails
     */
    public function testTheNameAlreadyCarriedWritesNothing(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);

        $renameId = $this->rename($userId, ' Ada ', $adminId);

        self::assertNull($renameId);
        self::assertSame('Ada', self::nameOf($userId));
        self::assertSame([], self::journal());
        self::assertSame([], $this->notices());
        self::assertSame([], $this->library->renamed);
    }

    /**
     * An administrator's rename handed over by the card is answered on the frame it names.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testTheCardsRenameIsAnsweredOnItsFrame(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);

        $answer = $this->askRename($userId, 'Grace', $adminId);

        self::assertNull($answer->error);
        self::assertSame(self::ACCEPT_KEY, $answer->acceptKey);
        self::assertSame(HilosSignalConstants::HILOS_USER_UPDATE, $answer->action);
        self::assertSame('Grace', self::nameOf($userId));
        self::assertCount(1, self::journal());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testRefusesAMissingPersonAndAnOverlongNameInTheirOwnWords(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);

        $missing = $this->askRename(self::MISSING_USER_ID, 'Grace', $adminId);
        self::assertSame(0, $this->hopsToThePerson, 'A missing person is refused before the agent is asked');
        $overlong = $this->askRename($userId, str_repeat('a', 65), $adminId);

        self::assertSame('User #' . self::MISSING_USER_ID . ' not found', $missing->error);
        self::assertSame('Failed to update user: User name exceeds maximum length of 64 characters', $overlong->error);
        self::assertSame('Ada', self::nameOf($userId));
        self::assertSame([], self::journal());
        self::assertSame([], $this->library->renamed);
    }

    /**
     * A folded account is refused in the card's own words, and the person's agent is not asked.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testRefusesAMergedPersonBeforeAskingTheAgent(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $survivorId = self::seedPerson('Survivor', admin: false);
        $userId = self::seedPerson('Ada', admin: false);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorId, '2026-10-08 12:00:00'],
        );

        $answer = $this->askRename($userId, 'Grace', $adminId);

        self::assertSame(0, $this->hopsToThePerson);
        self::assertSame(
            'Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
            $answer->error,
        );
        self::assertSame('Ada', self::nameOf($userId));
        self::assertSame([], self::journal());
    }

    /**
     * A hook that fails is news that did not get written, not a rename that did not happen.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testAFailingHookLeavesTheRenameStanding(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);
        $this->library->hookFailure = new ValidationException('The feed is closed');

        $answer = $this->askRename($userId, 'Grace', $adminId);

        self::assertNull($answer->error);
        self::assertSame('Grace', self::nameOf($userId));
        self::assertCount(1, self::journal());
        self::assertCount(1, $this->library->renamed);
    }

    /**
     * The author's account goes, the renamed person's history stays - with nobody named as author.
     *
     * @throws HilosException When a fixture row cannot be written or the rename fails
     */
    public function testErasingTheAuthorKeepsTheRowAndClearsTheAuthor(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);
        $this->rename($userId, 'Grace', $adminId);

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$adminId]);

        self::assertSame(
            [['user_id' => $userId, 'renamed_by_user_id' => null, 'old_name' => 'Ada', 'new_name' => 'Grace']],
            self::journal(),
        );
    }

    /**
     * Hands the library the rename the card forwards, and returns the answer it sent back.
     *
     * @param int $userId Person to rename
     * @param string $name Name the administrator typed
     * @param int $adminUserId Administrator behind the submit
     * @return HandoverAnswerSignalData The one answer the library addressed to the card
     * @throws HilosException When the frame handler fails
     */
    private function askRename(int $userId, string $name, int $adminUserId): HandoverAnswerSignalData
    {
        $this->outbox = [];
        $this->inLibrary(fn () => $this->library->onSignalAgent(
            new AgentSignalData(data: new AdminRenameSignalData(
                userId: $userId,
                name: $name,
                replySignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                acceptKey: self::ACCEPT_KEY,
                requestId: null,
                action: HilosSignalConstants::HILOS_USER_UPDATE,
                successMessage: null,
                adminUserId: $adminUserId,
            )),
            '',
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
        ));
        $this->carry();

        $answers = [];
        foreach ($this->outbox as $signal) {
            $data = $signal->data;
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE
                && $data instanceof AgentSignalData
                && $data->data instanceof HandoverAnswerSignalData) {
                $answers[] = $data->data;
            }
        }
        self::assertCount(1, $answers);

        return $answers[0];
    }

    /**
     * Asks the library to rename one person on somebody's word, the way a project asks it.
     *
     * @param int $userId Person to rename
     * @param string $name Name to give
     * @param ?int $renamedByUserId Person who did the rename, or null when the author is not a person
     * @return ?int Journal row the agent answered with, or null when it wrote none
     * @throws HilosException When a frame handler fails
     */
    private function rename(int $userId, string $name, ?int $renamedByUserId): ?int
    {
        $this->inLibrary(fn () => $this->library->ask(new UserRenameSignalData(
            userId: $userId,
            name: $name,
            renamedByUserId: $renamedByUserId,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: null,
            action: 'rename',
            successMessage: null,
            answerSignal: null,
        )));

        return $this->carry()?->renameId;
    }

    /**
     * Carries the frames between the library and the person's agent until neither has more to say.
     *
     * @return ?UserRenameDoneSignalData The last answer the agent gave, or null when it gave none
     * @throws HilosException When a frame handler fails
     */
    private function carry(): ?UserRenameDoneSignalData
    {
        $answer = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($name === HilosSignalConstants::HILOS_USER_RENAME) {
                $this->hopsToThePerson++;
                $this->deliverToPerson($signal);
                continue;
            }
            if ($name === HilosSignalConstants::HILOS_USER_RENAME_DONE) {
                $data = $signal->data;
                self::assertInstanceOf(AgentSignalData::class, $data);
                self::assertInstanceOf(UserRenameDoneSignalData::class, $data->data);
                $answer = $data->data;
                $this->inLibrary(fn () => $this->library->onSignalAgent($data, '', $name));
                continue;
            }
            $this->outbox[] = $signal;
        }

        return $answer;
    }

    /** @return list<NotificationEmitSignalData> Notices queued for the notifications library */
    private function notices(): array
    {
        $notices = [];
        foreach ($this->outbox as $signal) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_NOTIFICATION_EMIT) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(NotificationEmitSignalData::class, $signal->data->data);
            $notices[] = $signal->data->data;
        }

        return $notices;
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
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), $step);
    }

    /**
     * @param string $name Name the row carries
     * @param bool $admin Whether the row is an administrator
     * @return int Id of the inserted row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO `hilos_user` (`name`, `admin`) VALUES (?, ?)', [$name, (int)$admin]);

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Person to read
     * @return string The name the table carries now, past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function nameOf(int $userId): string
    {
        Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);

        return (string)$row['name'];
    }

    /**
     * @return list<array{user_id: int, renamed_by_user_id: ?int, old_name: string, new_name: string}> Journal rows in id order
     * @throws DatabaseException When the query fails
     */
    private static function journal(): array
    {
        $rows = [];
        foreach (Database::sql('SELECT * FROM `hilos_user_rename` ORDER BY `id`')->rows() as $row) {
            $rows[] = [
                'user_id' => (int)$row['user_id'],
                'renamed_by_user_id' => $row['renamed_by_user_id'] === null ? null : (int)$row['renamed_by_user_id'],
                'old_name' => (string)$row['old_name'],
                'new_name' => (string)$row['new_name'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<int> Journal row ids in id order
     * @throws DatabaseException When the query fails
     */
    private static function journalIds(): array
    {
        $ids = [];
        foreach (Database::sql('SELECT `id` FROM `hilos_user_rename` ORDER BY `id`')->rows() as $row) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }
}

/**
 * The framework's users library under a test name, watching its rename hook and able to fail it.
 */
final class UsersLibraryRenameTestLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_users_library_rename';

    /** @var list<UserRename> Journal rows the hook was handed, in order */
    public array $renamed = [];

    /**
     * Opens the protected rename ask to the test.
     *
     * @param UserRenameSignalData $ask Whom to rename, to what, and whom to answer
     * @throws HilosException Whatever the framework's ask raises
     */
    public function ask(UserRenameSignalData $ask): void
    {
        $this->askRename($ask);
    }

    /** Failure the hook raises after it has seen the row, or null to let it pass. */
    public ?HilosException $hookFailure = null;

    /**
     * @param UserRename $rename Journal row of the rename just committed
     * @throws HilosException The failure the case asked the hook to raise
     */
    public function afterUserRenamed(UserRename $rename): void
    {
        $this->renamed[] = $rename;
        if ($this->hookFailure !== null) {
            throw $this->hookFailure;
        }
    }
}
