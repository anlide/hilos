<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;

/**
 * The ordinary edits of one person are written by that person's agent and by nobody else (HIL-1404).
 *
 * The libraries keep judging and what follows, but their claims over the person's row no longer
 * reach an edit: the users library that renames somebody itself is refused by the truth source,
 * and the same rename handed to the person's agent goes through. The agent writes the name with
 * its journal row, the admin flag and the block, and answers every frame - the sessions library
 * finishes only on that answer. Two removals of the last two administrators crossing each other
 * are told apart by the mark the sessions library keeps while a removal is on its way.
 *
 * Every writer runs in its own frame and under the claims its own class declares, and the frames
 * between them are carried by the case.
 */
final class UserAgentPersonEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the admin's browser. */
    private const string ACCEPT_KEY = 'accept-1';

    private PersonEditsTestUsersLibrary $users;

    private PersonEditsTestSessionsLibrary $sessions;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->users = new PersonEditsTestUsersLibrary();
        $this->sessions = new PersonEditsTestSessionsLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
        OwnershipDeclaration::claimDb($this->sessions::class, $this->sessions->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->users->getId(), $this->sessions->getId()] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryRenamingAPersonItselfIsRefused(): void
    {
        $userId = self::seedPerson('Ada', admin: false);

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->users->getId()),
                static fn () => Hilos::$db->users[$userId]->actions->rename('Grace'),
            );
            self::fail('The users library must not edit a person past their agent');
        } catch (WriteNotAllowedException $refusal) {
            self::assertStringContainsString($this->users->getId(), $refusal->getMessage());
        }

        self::assertSame('Ada', self::personRow($userId)['name']);
    }

    /** @throws HilosException When a fixture row cannot be written or read */
    public function testTheUsersLibraryCannotWriteAPersonsThemePick(): void
    {
        $userId = self::seedPerson('Ada', admin: false);

        $this->expectException(WriteNotAllowedException::class);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            static fn () => Hilos::$db->users[$userId]->actions->setThemePick('dark'),
        );
    }

    /** @throws HilosException When a fixture row cannot be written or a frame fails */
    public function testTheAgentWritesTheThemePickAndAnswersTheCoordinator(): void
    {
        $userId = self::seedPerson('Ada', admin: false);
        $ask = new UserThemePickWriteSignalData($userId, 'dark', 'theme_pick_done', self::ACCEPT_KEY, 'req-1', 'theme_pick', 'Saved');

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE, $ask));

        $answer = $this->answer('theme_pick_done');
        self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertSame($ask->toArray(), $answer->ask->toArray());
        self::assertSame('dark', self::personRow($userId)['theme_pick']);
        self::assertNull(self::personRow($userId)['last_activity']);

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE, $ask));
        $repeatSignal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($repeatSignal);
        self::assertSame('theme_pick_done', $repeatSignal->signalName->getName(), 'The unchanged choice must not emit a DB update');
        self::assertInstanceOf(AgentSignalData::class, $repeatSignal->data);
        $repeatAnswer = $repeatSignal->data->data;
        self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $repeatAnswer);
        self::assertNull($repeatAnswer->error);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The unchanged choice must emit only its answer');
        self::assertSame('dark', self::personRow($userId)['theme_pick']);
    }

    /** @throws HilosException When a fixture row cannot be written or a frame fails */
    public function testTheThemePickOfAFoldedAccountIsRefused(): void
    {
        $survivorId = self::seedPerson('Survivor', admin: false);
        $userId = self::seedPerson('Folded', admin: false);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NOW())',
            [$userId, $survivorId],
        );

        $this->deliverToPerson($this->frame(
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE,
            new UserThemePickWriteSignalData($userId, 'dark', 'theme_pick_done', self::ACCEPT_KEY, null, 'theme_pick', null),
        ));

        $answer = $this->answer('theme_pick_done');
        self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $answer);
        self::assertNotNull($answer->error);
        self::assertStringContainsString('merged', strtolower($answer->error));
        self::assertNull(self::personRow($userId)['theme_pick']);
    }

    /** @throws HilosException When a frame or fixture query fails */
    public function testTheThemePickOfAMissingPersonIsRefused(): void
    {
        $userId = self::seedPerson('Gone', admin: false);
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $ask = new UserThemePickWriteSignalData($userId, 'dark', 'theme_pick_done', self::ACCEPT_KEY, null, 'theme_pick', null);

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE, $ask));

        $answer = $this->answer('theme_pick_done');
        self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $answer);
        self::assertSame($ask->toArray(), $answer->ask->toArray());
        self::assertSame("No such user: {$userId}", $answer->error);
        self::assertNull($answer->errorType);
    }

    /** @throws HilosException When fixture rows cannot be written or a frame fails */
    public function testAThemePickFrameForAnotherPersonIsRefused(): void
    {
        $userId = self::seedPerson('Ada', admin: false);
        $otherId = self::seedPerson('Grace', admin: false);

        $this->expectException(AgentException::class);
        $this->asPerson($userId, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(data: new UserThemePickWriteSignalData(
                $otherId, 'dark', 'theme_pick_done', self::ACCEPT_KEY, null, 'theme_pick', null,
            )),
            '',
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE,
        ));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testTheAgentWritesTheNameAndItsJournalRowAndAnswersWithTheRow(): void
    {
        $adminId = self::seedPerson('Root', admin: true);
        $userId = self::seedPerson('Ada', admin: false);

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_RENAME, new UserRenameSignalData(
            userId: $userId,
            name: 'Grace',
            renamedByUserId: $adminId,
            replySignal: HilosSignalConstants::HILOS_USER_RENAME_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: null,
            action: HilosSignalConstants::HILOS_USER_UPDATE,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
        )));

        $answer = $this->answer(HilosSignalConstants::HILOS_USER_RENAME_DONE);
        self::assertInstanceOf(UserRenameDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertSame('Grace', self::personRow($userId)['name']);
        Database::sql('SELECT `id`, `renamed_by_user_id` FROM `hilos_user_rename` WHERE `user_id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);
        self::assertSame((int)$row['id'], $answer->renameId);
        self::assertSame($adminId, (int)$row['renamed_by_user_id']);
        self::assertSame(HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE, $answer->ask->answerSignal);
    }

    /**
     * A merged account renamed by its own owner is refused, and the refusal reaches their window.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testARefusedOwnRenameIsAnsweredToTheConnectionAsAnActionError(): void
    {
        $survivorId = self::seedPerson('Survivor', admin: false);
        $userId = self::seedPerson('Folded', admin: false);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NOW())',
            [$userId, $survivorId],
        );

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_RENAME, new UserRenameSignalData(
            $userId,
            'Grace',
            $userId,
            HilosSignalConstants::HILOS_USER_RENAME_DONE,
            self::ACCEPT_KEY,
            null,
            'rename',
            null,
            null,
        )));
        $answer = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($answer);
        self::assertInstanceOf(AgentSignalData::class, $answer->data);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            fn () => $this->users->onSignalAgent($answer->data, '', HilosSignalConstants::HILOS_USER_RENAME_DONE),
        );

        $refusal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($refusal);
        self::assertSame(SignalConstants::ACTION_ERROR, $refusal->signalName->getName());
        self::assertSame('Folded', self::personRow($userId)['name']);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_user_rename`')->rows());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testTheAgentWritesBothFlagsAndTheSessionsLibraryAnswersTheCardAfterwards(): void
    {
        $userId = self::seedPerson('Ada', admin: false);

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_ADMIN_WRITE, new UserAdminWriteSignalData(
            userId: $userId,
            admin: true,
            replySignal: HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::HILOS_USER_ADMIN_SET,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
        )));
        $adminDone = $this->answer(HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE);
        self::assertInstanceOf(UserAdminWriteDoneSignalData::class, $adminDone);
        self::assertNull($adminDone->error);
        self::assertSame(1, (int)self::personRow($userId)['admin']);

        $this->deliverToPerson($this->frame(HilosSignalConstants::HILOS_USER_BLOCK_WRITE, new UserBlockWriteSignalData(
            userId: $userId,
            block: true,
            by: 1,
            replySignal: HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-2',
            action: HilosSignalConstants::HILOS_USER_BLOCK_SET,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
        )));
        $blockDone = $this->answer(HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE);
        self::assertInstanceOf(UserBlockWriteDoneSignalData::class, $blockDone);
        self::assertNull($blockDone->error);
        self::assertSame(1, (int)self::personRow($userId)['block']);

        $this->inSessions(fn () => $this->sessions->onSignalAgent(
            new AgentSignalData(data: $adminDone),
            '',
            HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE,
        ));
        $card = $this->answer(HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $card);
        self::assertNull($card->error);
        self::assertSame('req-1', $card->requestId);
        self::assertSame('Admin rights granted. No open tabs: they apply at the next sign-in', $card->successMessage);

        $this->inSessions(fn () => $this->sessions->onSignalAgent(
            new AgentSignalData(data: $blockDone),
            '',
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
        ));
        $card = $this->answer(HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $card);
        self::assertNull($card->error);
        self::assertSame('req-2', $card->requestId);
        self::assertStringStartsWith('Account blocked. Sessions ended: ', (string)$card->successMessage);
    }

    /**
     * Two administrators, two revokes crossing each other: the second sees the first as done.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testARevokeOnItsWayLeavesTheOtherAdministratorTheLastOne(): void
    {
        $firstId = self::seedPerson('Root', admin: true);
        $secondId = self::seedPerson('Grace', admin: true);

        $this->command(CliCommands::ADMIN_REVOKE, $firstId);
        $onItsWay = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($onItsWay);
        self::assertSame(HilosSignalConstants::HILOS_USER_ADMIN_COMMAND, $onItsWay->signalName->getName());

        $this->command(CliCommands::ADMIN_REVOKE, $secondId);
        $refused = $this->reply();
        self::assertFalse($refused->isOk());
        self::assertSame('The last active administrator cannot lose the rights', $refused->payload[CommandConstants::FIELD_MESSAGE]);

        $this->deliverToPerson($onItsWay);
        $answer = $this->answer(HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE);
        self::assertInstanceOf(UserAdminCommandDoneSignalData::class, $answer);
        $this->inSessions(fn () => $this->sessions->onSignalAgent(
            new AgentSignalData(data: $answer),
            '',
            HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE,
        ));
        $done = $this->reply();
        self::assertTrue($done->isOk());
        self::assertFalse($done->payload[AdminCommandConstants::FIELD_ADMIN]);
        self::assertSame(0, (int)self::personRow($firstId)['admin']);
        self::assertSame(1, (int)self::personRow($secondId)['admin']);

        // The mark is gone with the answer, and the database now says the same thing it said.
        $this->command(CliCommands::ADMIN_REVOKE, $secondId);
        self::assertFalse($this->reply()->isOk());
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = self::seedPerson('Ada', admin: false);
        $otherId = self::seedPerson('Grace', admin: false);

        $this->expectException(AgentException::class);

        $this->asPerson($userId, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(data: new UserBlockWriteSignalData(
                $otherId,
                true,
                1,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
                self::ACCEPT_KEY,
                null,
                HilosSignalConstants::HILOS_USER_BLOCK_SET,
                null,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
            )),
            '',
            HilosSignalConstants::HILOS_USER_BLOCK_WRITE,
        ));
    }

    /**
     * @param string $name Agent-signal name the frame travels under
     * @param UserRenameSignalData|UserAdminWriteSignalData|UserBlockWriteSignalData|UserThemePickWriteSignalData $ask Frame payload
     * @return SignalDTO The frame as the coordinator would have queued it
     * @throws HilosException When the frame cannot be queued or taken back
     */
    private function frame(
        string $name,
        UserRenameSignalData|UserAdminWriteSignalData|UserBlockWriteSignalData|UserThemePickWriteSignalData $ask,
    ): SignalDTO
    {
        $this->sessions->sendToAgent($name, $ask);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);

        return $signal;
    }

    /**
     * Takes the next queued frame, which must travel under the given name.
     *
     * @param string $name Agent-signal name expected next
     * @return mixed The frame's payload
     */
    private function answer(string $name): mixed
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);

                return $signal->data->data;
            }
        }

        self::fail("Nothing was sent under {$name}");
    }

    /**
     * @param string $command Command wire name
     * @param int $userId Person the command names
     */
    private function command(string $command, int $userId): void
    {
        $this->inSessions(fn () => $this->sessions->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-' . $userId,
                command: $command,
                payload: [AdminCommandConstants::FIELD_USER_ID => $userId, AdminCommandConstants::FIELD_ADMIN => false],
            ),
            '',
            '',
        ));
    }

    /** @return CommandReplyDTO The next command reply queued */
    private function reply(): CommandReplyDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                return $signal->data;
            }
        }

        self::fail('The command was not answered');
    }

    /**
     * @template T
     * @param callable(): T $step Step to run as the sessions library
     * @return T Whatever the step returns
     */
    private function inSessions(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->sessions->getId()), $step);
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
     * @return array<string, mixed> Name and both flags, past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function personRow(int $userId): array
    {
        Database::sql('SELECT `name`, `admin`, `block`, `last_activity`, `theme_pick` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);

        return $row;
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class PersonEditsTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_person_edits_users_library';
}

/** The framework's sessions library under a test name, with nothing overridden. */
final class PersonEditsTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_person_edits_sessions_library';
}
