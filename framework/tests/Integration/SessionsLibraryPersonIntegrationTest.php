<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Impersonation\ImpersonationMessages;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AdminAudience;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserAdminWriteDoneSignalData;
use Hilos\Users\DTO\UserAdminWriteSignalData;
use Hilos\Users\DTO\UserBlockWriteDoneSignalData;
use Hilos\Users\DTO\UserBlockWriteSignalData;

/**
 * The person as the framework's sessions library mints and judges it (HIL-1197) and as the
 * person's own agent flags and blocks it (HIL-1404), and an account folded into another one, or
 * gone, as the agent refuses it at the entry of the frame (HIL-1199, HIL-1703).
 *
 * The library and the agent under test are the base classes with nothing overridden, so what
 * answers is the framework's own body over `hilos_user`, and no project stands between the two.
 * Every write runs in its writer's own frame and under the claim that writer's class declares:
 * in a frame a write passes only by that agent's own grant, which is what proves each holds the
 * share of the row it writes. A refusal is read the way the sessions library reads it: off the
 * frame the agent answers with, taken from the queue of a router of this case's own.
 *
 * Whom a takeover may reach is judged with the impersonation settings of the installation
 * (HIL-1170), read from a fixture catalog whose defaults a case switches off: a default is the
 * value in force when no row is stored, so no settings row is written.
 */
final class SessionsLibraryPersonIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int MISSING_USER_ID = 9999;

    /** Accept key standing in for the administrator's browser. */
    private const string ACCEPT_KEY = 'accept-1';

    private SessionsLibraryPersonTestLibrary $library;

    private ?SettingsAccessor $previousSetting = null;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->library = new SessionsLibraryPersonTestLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
        $this->previousSetting = Hilos::$setting;
        self::switchOff();
        AccountStandingResolver::forgetAll();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        AccountStandingResolver::forgetAll();
        Hilos::$setting = $this->previousSetting;
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When the administrator cannot be minted or read
     */
    public function testMintsAnAdministratorForASessionCarryingNobody(): void
    {
        $userId = $this->inLibrary(fn (): int => $this->library->mintAdmin());

        self::assertGreaterThan(0, $userId);
        $row = self::personRow($userId);
        self::assertSame(1, (int)$row['admin']);
        self::assertMatchesRegularExpression('/^Admin\d{4}$/', (string)$row['name']);
        self::assertSame(1, self::personCount());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the flag cannot be read
     */
    public function testGrantsAndRevokesTheAdminFlag(): void
    {
        $userId = self::seedPerson('Grace', admin: false, block: false);

        $this->asPerson($userId, static fn (SessionsLibraryPersonTestUserAgent $agent) => $agent->grant(true));
        self::assertSame(1, (int)self::personRow($userId)['admin']);

        $this->asPerson($userId, static fn (SessionsLibraryPersonTestUserAgent $agent) => $agent->grant(false));
        self::assertSame(0, (int)self::personRow($userId)['admin']);
    }

    /**
     * A frame to a person with no row is answered, not raised: the entry of the frame refuses it
     * in the words the card reads, with nothing held back.
     *
     * @throws HilosException When the frame cannot be queued or the agent fails on it
     */
    public function testRefusesToGrantAPersonWithNoRow(): void
    {
        self::assertRefusedInWords(
            $this->askAdmin(self::MISSING_USER_ID, true),
            'No such user: ' . self::MISSING_USER_ID,
        );
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the flag cannot be read
     */
    public function testBlocksAndUnblocksThePerson(): void
    {
        $userId = self::seedPerson('Linus', admin: false, block: false);

        $this->asPerson($userId, static fn (SessionsLibraryPersonTestUserAgent $agent) => $agent->block(true));
        self::assertSame(1, (int)self::personRow($userId)['block']);

        $this->asPerson($userId, static fn (SessionsLibraryPersonTestUserAgent $agent) => $agent->block(false));
        self::assertSame(0, (int)self::personRow($userId)['block']);
    }

    /**
     * @throws HilosException When the frame cannot be queued or the agent fails on it
     */
    public function testRefusesToBlockAPersonWithNoRow(): void
    {
        self::assertRefusedInWords(
            $this->askBlock(self::MISSING_USER_ID, true),
            'No such user: ' . self::MISSING_USER_ID,
        );
    }

    /**
     * An account folded into another one gets no rights and loses none: both ways are refused at
     * the entry of the frame, before the write, and the flag stays as it was.
     *
     * @throws HilosException When a fixture row cannot be written or the flag cannot be read
     */
    public function testRefusesTheAdminFlagOfAMergedAccountBothWays(): void
    {
        $survivorId = self::seedPerson('Survivor', admin: false, block: false);
        $plainId = self::seedPerson('Plain', admin: false, block: true);
        $adminId = self::seedPerson('Admin', admin: true, block: true);
        self::seedMerge($plainId, $survivorId);
        self::seedMerge($adminId, $survivorId);

        foreach ([[$plainId, true], [$adminId, false]] as [$userId, $admin]) {
            self::assertRefusedInWords(
                $this->askAdmin($userId, $admin),
                AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
            );
        }

        self::assertSame(0, (int)self::personRow($plainId)['admin']);
        self::assertSame(1, (int)self::personRow($adminId)['admin']);
    }

    /**
     * An account folded into another one is neither blocked nor unblocked by an administrator:
     * the merge closed its sign-in, and that is not a block to lift. Both ways are refused at the
     * entry of the frame.
     *
     * @throws HilosException When a fixture row cannot be written or the flag cannot be read
     */
    public function testRefusesTheBlockOfAMergedAccountBothWays(): void
    {
        $survivorId = self::seedPerson('Survivor', admin: false, block: false);
        $closedId = self::seedPerson('Closed', admin: false, block: true);
        $reopenedId = self::seedPerson('Reopened', admin: false, block: false);
        self::seedMerge($closedId, $survivorId);
        self::seedMerge($reopenedId, $survivorId);

        foreach ([[$closedId, false], [$reopenedId, true]] as [$userId, $block]) {
            self::assertRefusedInWords(
                $this->askBlock($userId, $block),
                AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
            );
        }

        self::assertSame(1, (int)self::personRow($closedId)['block']);
        self::assertSame(0, (int)self::personRow($reopenedId)['block']);
    }

    /**
     * A merged administrator is out of the administrators' circle even with its block lifted
     * past the library - who administers the installation does not follow that flag alone.
     *
     * @throws HilosException When a fixture row cannot be written or the circle cannot be read
     */
    public function testLeavesAMergedAdministratorOutOfTheCircle(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $mergedId = self::seedPerson('Folded root', admin: true, block: false);
        self::seedPerson('Blocked root', admin: true, block: true);
        self::seedMerge($mergedId, $adminId);

        self::assertSame([$adminId], AdminAudience::all());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testLetsAnAdministratorTakeAnExistingPersonOver(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $targetId = self::seedPerson('Ada', admin: false, block: false);

        $this->library->mayImpersonate($adminId, $targetId);

        $this->addToAssertionCount(1);
    }

    /**
     * An unprivileged asker is refused as such whatever it named, so it cannot learn whether an
     * id exists by asking to become it.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesAnAskerWhoIsNoAdministratorBeforeLookingForTheTarget(): void
    {
        $askerId = self::seedPerson('Ada', admin: false, block: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Session is not an admin session');

        $this->library->mayImpersonate($askerId, self::MISSING_USER_ID);
    }

    /**
     * @throws HilosException When the check cannot read the person
     */
    public function testRefusesAnAskerWithNoRowAsNoAdministrator(): void
    {
        $targetId = self::seedPerson('Ada', admin: false, block: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Session is not an admin session');

        $this->library->mayImpersonate(self::MISSING_USER_ID, $targetId);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesAnAdministratorATargetWithNoRow(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No such user: ' . self::MISSING_USER_ID);

        $this->library->mayImpersonate($adminId, self::MISSING_USER_ID);
    }

    /**
     * Impersonation switched off for the product refuses every takeover, before whom it would reach is asked.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesEveryTakeoverWhenImpersonationIsSwitchedOff(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $targetId = self::seedPerson('Ada', admin: false, block: false);
        self::switchOff(ImpersonationSettings::ALLOWED_KEY);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ImpersonationMessages::SWITCHED_OFF);

        $this->library->mayImpersonate($adminId, $targetId);
    }

    /**
     * A blocked person may be taken over by default, for service work (HIL-289), and not once the row is switched off.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesABlockedPersonOnlyWhenThatRowIsSwitchedOff(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $targetId = self::seedPerson('Ada', admin: false, block: true);

        $this->library->mayImpersonate($adminId, $targetId);

        self::switchOff(ImpersonationSettings::BLOCKED_KEY);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ImpersonationMessages::BLOCKED_OFF);

        $this->library->mayImpersonate($adminId, $targetId);
    }

    /**
     * The row of the blocked does not reach a person who is not blocked.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testTheBlockedRowLeavesEverybodyElseAlone(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $targetId = self::seedPerson('Ada', admin: false, block: false);
        self::switchOff(ImpersonationSettings::BLOCKED_KEY, ImpersonationSettings::FROZEN_KEY, ImpersonationSettings::EQUAL_KEY);

        $this->library->mayImpersonate($adminId, $targetId);

        $this->addToAssertionCount(1);
    }

    /**
     * Another administrator may be taken over by default, and not once the row of the equal is switched off.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesAnotherAdministratorOnlyWhenThatRowIsSwitchedOff(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        $otherAdminId = self::seedPerson('Grace', admin: true, block: false);

        $this->library->mayImpersonate($adminId, $otherAdminId);

        self::switchOff(ImpersonationSettings::EQUAL_KEY);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ImpersonationMessages::EQUAL_OFF);

        $this->library->mayImpersonate($adminId, $otherAdminId);
    }

    /**
     * The asker naming their own user is not another administrator: the library refuses that one in its own words, later.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testTheAskerNamingThemselvesIsNotAnotherAdministrator(): void
    {
        $adminId = self::seedPerson('Root', admin: true, block: false);
        self::switchOff(ImpersonationSettings::EQUAL_KEY);

        $this->library->mayImpersonate($adminId, $adminId);

        $this->addToAssertionCount(1);
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return SessionsLibraryPersonTestUserAgent The person's agent with its two flag writes opened
     */
    protected function newPersonAgent(string $userId): SessionsLibraryPersonTestUserAgent
    {
        return new SessionsLibraryPersonTestUserAgent($userId);
    }

    /**
     * Hands the person's agent the sessions library's admin flag frame, in the agent's own frame,
     * and reads its answer off the queue.
     *
     * @param int $userId Person the frame names
     * @param bool $admin New admin flag
     * @return UserAdminWriteDoneSignalData What the agent answered the library
     */
    private function askAdmin(int $userId, bool $admin): UserAdminWriteDoneSignalData
    {
        $ask = new UserAdminWriteSignalData(
            userId: $userId,
            admin: $admin,
            replySignal: HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-admin-' . $userId,
            action: HilosSignalConstants::HILOS_USER_ADMIN_SET,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE,
        );
        $done = $this->answerTo($userId, $ask, HilosSignalConstants::HILOS_USER_ADMIN_WRITE);
        self::assertInstanceOf(UserAdminWriteDoneSignalData::class, $done);

        return $done;
    }

    /**
     * Hands the person's agent the sessions library's block flag frame, in the agent's own frame,
     * and reads its answer off the queue.
     *
     * @param int $userId Person the frame names
     * @param bool $block New block flag
     * @return UserBlockWriteDoneSignalData What the agent answered the library
     */
    private function askBlock(int $userId, bool $block): UserBlockWriteDoneSignalData
    {
        $ask = new UserBlockWriteSignalData(
            userId: $userId,
            block: $block,
            by: 1,
            replySignal: HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-block-' . $userId,
            action: HilosSignalConstants::HILOS_USER_BLOCK_SET,
            successMessage: null,
            answerSignal: HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE,
        );
        $done = $this->answerTo($userId, $ask, HilosSignalConstants::HILOS_USER_BLOCK_WRITE);
        self::assertInstanceOf(UserBlockWriteDoneSignalData::class, $done);

        return $done;
    }

    /**
     * Runs the production handler of the person's agent on one frame and takes the answer sent
     * under the ask's reply signal off the queue.
     *
     * @param int $userId Person whose agent takes the frame
     * @param UserAdminWriteSignalData|UserBlockWriteSignalData $ask The frame's payload
     * @param string $name Agent-signal name the frame travels under
     * @return mixed Payload of the answer
     */
    private function answerTo(int $userId, UserAdminWriteSignalData|UserBlockWriteSignalData $ask, string $name): mixed
    {
        $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
            new AgentSignalData(data: $ask),
            '',
            $name,
        ));

        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $ask->replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);

                return $signal->data->data;
            }
        }

        self::fail("Nothing was sent under {$ask->replySignal}");
    }

    /**
     * The refusal the card reads: the sentence alone, with no failure held back beside it.
     *
     * @param UserAdminWriteDoneSignalData|UserBlockWriteDoneSignalData $done The agent's answer
     * @param string $words Sentence the refusal must carry
     */
    private static function assertRefusedInWords(UserAdminWriteDoneSignalData|UserBlockWriteDoneSignalData $done, string $words): void
    {
        self::assertSame($words, $done->error);
        self::assertNull($done->errorType);
        self::assertNull($done->errorDetail);
    }

    /**
     * Mounts the impersonation settings with these yes-or-no keys switched off and every other on its default.
     *
     * A fresh accessor, because an accessor asks its catalog once.
     *
     * @param string ...$keys Keys to switch off
     */
    private static function switchOff(string ...$keys): void
    {
        SessionsLibraryPersonTestImpersonationCatalog::$switchedOff = array_values($keys);
        Hilos::$setting = new SettingsAccessor(SessionsLibraryPersonTestImpersonationCatalog::class);
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
     * Inserts a person row past every action, so the library reads a state no action wrote.
     *
     * @param string $name Name the row carries
     * @param bool $admin Whether the row is an administrator
     * @param bool $block Whether the row is blocked
     * @return int Id of the inserted row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(string $name, bool $admin, bool $block): int
    {
        Database::sql(
            'INSERT INTO `hilos_user` (`name`, `admin`, `block`) VALUES (?, ?, ?)',
            [$name, (int)$admin, (int)$block],
        );

        return Database::lastInsertId();
    }

    /**
     * Reads one person straight from the database, past every in-memory collection.
     *
     * @param int $userId Person to read
     * @return array<string, mixed> Name and both flags of the row
     * @throws DatabaseException When the query fails
     */
    private static function personRow(int $userId): array
    {
        Database::sql('SELECT `name`, `admin`, `block` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);

        return $row;
    }

    /**
     * Folds one account into another past every action, the way an earlier merge left it.
     *
     * @param int $userId Folded account
     * @param int $survivorUserId Account it was folded into
     * @throws DatabaseException When the insert fails
     */
    private static function seedMerge(int $userId, int $survivorUserId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NOW())',
            [$userId, $survivorUserId],
        );
    }

    /**
     * @return int Rows the person table holds
     * @throws DatabaseException When the count fails
     */
    private static function personCount(): int
    {
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_user`');

        return (int)Database::row()['count'];
    }
}

/**
 * The framework's sessions library under a test name, with no method of the person overridden.
 *
 * The two methods it opens are protected on the base; the wrappers below only make them
 * reachable from the case, and add nothing to what they do.
 */
final class SessionsLibraryPersonTestLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_sessions_library_person';

    /**
     * Opens the protected administrator mint to the test.
     *
     * @return int Id of the administrator just minted
     * @throws HilosException Whatever the framework's write raises
     */
    public function mintAdmin(): int
    {
        return $this->mintAdminUser();
    }

    /**
     * Opens the protected takeover check to the test.
     *
     * @param int $adminUserId User the acting session currently carries
     * @param int $targetUserId User that session asks to act as
     * @throws HilosException Whatever the framework's check raises
     */
    public function mayImpersonate(int $adminUserId, int $targetUserId): void
    {
        $this->assertImpersonationAllowed($adminUserId, $targetUserId);
    }
}

/**
 * The framework's agent of one person, with its two flag writes opened to the case and nothing
 * overridden.
 */
final class SessionsLibraryPersonTestUserAgent extends AbstractUserAgent
{
    /**
     * Opens the protected admin flag write to the test.
     *
     * @param bool $admin New admin flag
     * @throws HilosException Whatever the framework's write raises
     */
    public function grant(bool $admin): void
    {
        $this->writeAdminFlag($admin);
    }

    /**
     * Opens the protected block flag write to the test.
     *
     * @param bool $block New block flag
     * @throws HilosException Whatever the framework's write raises
     */
    public function block(bool $block): void
    {
        $this->writeBlockFlag($block);
    }
}

/**
 * The framework's impersonation settings with the defaults a case switches off.
 *
 * Every key of the fragment, the scope included, so the readers find what a project folding the
 * fragment in would give them; only the yes-or-no keys a case names stand off.
 */
final class SessionsLibraryPersonTestImpersonationCatalog implements CatalogProviderInterface
{
    /** @var list<string> Yes-or-no keys whose default the case switched off */
    public static array $switchedOff = [];

    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        $catalog = ImpersonationSettingsCatalog::getCatalog();
        foreach (self::$switchedOff as $key) {
            $catalog[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = false;
        }

        return $catalog;
    }
}
