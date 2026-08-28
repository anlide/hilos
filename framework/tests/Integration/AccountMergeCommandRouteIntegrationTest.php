<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Collection\Identities;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\DTO\AccountMergeResultSignalData;
use Hilos\Users\DTO\AccountMergeSignalData;

/**
 * The agent side of the account:merge route and of the browser frame beside it (HIL-378, HIL-729).
 *
 * An integration case rather than a unit one for the reason
 * {@see ImpersonationCommandRouteIntegrationTest} gives: every branch below the wire name ends
 * in real identity rows moving inside a real transaction, and a case that faked the identities
 * would pin its own fake instead of the path an operator walks.
 *
 * What is pinned is everything the FRAMEWORK owns, which since HIL-729 is the whole operation
 * bar two questions. The guards that need no project - two ids that are the same, two accounts
 * that each hold a password and nobody saying which stays - the transaction, the identity
 * re-point, the password outcome read back off the account and the loser's forced sign-out all
 * live here. What a project answers is whether these two accounts may be merged at all and
 * what it keeps for a person, and what is pinned about those is the SHAPE of the seams rather
 * than any answer: both are reached, they are reached in the order that keeps the refusals
 * honest, the row move runs where a failure still rolls the merge back, and a project that
 * wired neither refuses instead of half-merging.
 *
 * The browser half is the same core through another door, so it is driven here too: one case,
 * enough to pin that the door leads to the same place and answers on a frame rather than on
 * the operator's socket.
 */
final class AccountMergeCommandRouteIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Survivor of every merge below; a framework table carries no FK to a project user. */
    private const int SURVIVOR_USER_ID = 11;

    /** Loser of every merge below. */
    private const int LOSER_USER_ID = 12;

    /** Accept key standing in for the browser that submitted the admin-table action. */
    private const string ACCEPT_KEY = 'accept-1';

    /** Precomputed so a case seeding two passwords does not pay bcrypt twice. */
    private const string SEED_PASSWORD = 'merge-route-secret-42';

    /**
     * @var list<string> Framework tables this case needs. `hilos_setting` is the one framework
     *     collection loaded eagerly, so mounting the context reaches for it.
     */
    private const array TABLES = ['hilos_identity', 'hilos_session', 'hilos_setting'];

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /** @var int Rolling source of unique addresses within one case */
    private int $emailCounter = 0;

    /**
     * @throws HilosException When a stub statement fails or the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new AccountMergeRouteTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    public function testTheWireNameDeclaresTheRouteOnTheLibrary(): void
    {
        self::assertContains(CliCommands::ACCOUNT_MERGE, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
    }

    public function testTheBrowsersFrameDeclaresTheSignalOnTheLibrary(): void
    {
        self::assertSame(
            AccountMergeSignalData::class,
            AbstractSessionsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_ACCOUNT_MERGE] ?? null,
        );
    }

    /**
     * A merge asks both seams and reports the framework's count beside the project's map.
     *
     * @throws HilosException When a seed, the merge, or a read-back fails
     */
    public function testAMergeAsksBothSeamsAndReportsWhatEachMoved(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk(), 'A wired merge answers ok');
        self::assertSame([self::SURVIVOR_USER_ID, self::LOSER_USER_ID], $agent->vouchedFor);
        self::assertSame([self::SURVIVOR_USER_ID, self::LOSER_USER_ID], $agent->moved);
        self::assertSame(1, $reply->payload[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED]);
        self::assertSame(
            [AccountMergeRouteTestAgent::ROW_FAMILY => AccountMergeRouteTestAgent::ROWS_MOVED],
            $reply->payload[AccountMergeCommandConstants::FIELD_ROWS_MOVED],
        );
        self::assertSame(
            PasswordFate::NONE->value,
            $reply->payload[AccountMergeCommandConstants::FIELD_PASSWORD_KEPT],
        );
        self::assertSame(
            self::SURVIVOR_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * The one refusal that needs nobody's help lands before the project is asked anything.
     *
     * @throws HilosException When the merge fails
     */
    public function testASelfMergeIsRefusedBeforeEitherSeamIsAsked(): void
    {
        $agent = new AccountMergeRouteTestAgent();

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::SURVIVOR_USER_ID);

        self::assertSame('Cannot merge a user into itself', $this->refusal());
        self::assertNull($agent->vouchedFor, 'The project is not asked about a merge that cannot be one');
        self::assertNull($agent->moved);
    }

    /**
     * A project refusing the pair answers the operator once and moves nothing.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testASeamRefusalBecomesOneErrorReplyAndMovesNothing(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();
        $agent->refuseWith = new ValidationException('Loser 12 is already merged');

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertSame('Loser 12 is already merged', $this->refusal());
        self::assertNull($agent->moved, 'A vouching refusal never reaches the row move');
        self::assertSame(
            self::LOSER_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * A project that wired neither seam refuses rather than half-merging.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAnUnwiredProjectRefusesEveryMerge(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);

        $this->sendCommand(new AccountMergeRouteTestUnwiredAgent(), self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertSame('Account merge is not wired in this project', $this->refusal());
        self::assertSame(
            self::LOSER_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * The passwords are weighed only after the project has vouched for both accounts.
     *
     * The order is the whole point: an id that names nobody must be refused as such rather
     * than as a password question, so the seam runs first and the framework guard second.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testTwoPasswordsAreWeighedOnlyAfterTheProjectHasVouched(): void
    {
        $this->seedPassword(self::SURVIVOR_USER_ID);
        $this->seedPassword(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertStringContainsString('--password', $this->refusal());
        self::assertSame([self::SURVIVOR_USER_ID, self::LOSER_USER_ID], $agent->vouchedFor);
        self::assertNull($agent->moved, 'The row move is behind the password question, not before it');
    }

    /**
     * A fate named on the command line reaches the identity re-point.
     *
     * @throws HilosException When a seed, the merge, or a read-back fails
     */
    public function testANamedFateDecidesWhichPasswordTheAccountKeeps(): void
    {
        $survivorEmail = $this->seedPassword(self::SURVIVOR_USER_ID);
        $this->seedPassword(self::LOSER_USER_ID);

        $this->sendCommand(
            new AccountMergeRouteTestAgent(),
            self::SURVIVOR_USER_ID,
            self::LOSER_USER_ID,
            PasswordFate::SURVIVOR,
        );

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk(), 'A named fate answers the question the merge refused on');
        self::assertSame(
            PasswordFate::SURVIVOR->value,
            $reply->payload[AccountMergeCommandConstants::FIELD_PASSWORD_KEPT],
        );
        self::assertSame(
            $survivorEmail,
            $this->identities()->findPasswordByUser(self::SURVIVOR_USER_ID)?->identifier,
        );
    }

    /**
     * The project's row move runs inside the transaction: its failure undoes the re-point.
     *
     * Read back through a query rather than the collection, whose object cache still holds the
     * mutated-then-rolled-back identity.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAFailingRowMoveRollsBackTheIdentityRePoint(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();
        $agent->failTheRowMove = true;

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertSame('The project could not move its rows', $this->refusal());
        self::assertSame(self::LOSER_USER_ID, self::identityOwner($loserEmail));
    }

    /**
     * A merged loser's live sessions are signed out, and its own tokens alone.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAMergeSignsOutTheLosersLiveSessionsAndNobodyElses(): void
    {
        self::seedSession('4f9c1b8e2d7a6053c4e1f8b90a2d3c56', self::LOSER_USER_ID);
        self::seedSession('00112233445566778899aabbccddeeff', self::SURVIVOR_USER_ID);

        $this->sendCommand(new AccountMergeRouteTestAgent(), self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertTrue($this->consumeReply()->isOk());
        self::assertNull(self::boundUserId('4f9c1b8e2d7a6053c4e1f8b90a2d3c56'));
        self::assertSame(self::SURVIVOR_USER_ID, self::boundUserId('00112233445566778899aabbccddeeff'));
    }

    /**
     * The browser's way in runs the same core and answers on a frame, not on the socket.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testTheBrowsersWayInAnswersOnAFrameAndNotOnTheSocket(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $agent->onSignalAgent(
            new AgentSignalData(new AccountMergeSignalData(
                self::SURVIVOR_USER_ID,
                self::LOSER_USER_ID,
                self::ACCEPT_KEY,
            )),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeResult();
        self::assertSame(self::ACCEPT_KEY, $result->acceptKey);
        $outcome = $result->outcome;
        self::assertInstanceOf(AccountMergeSummary::class, $outcome, 'A merge that went through hands back a summary');
        self::assertSame(
            [AccountMergeRouteTestAgent::ROW_FAMILY => AccountMergeRouteTestAgent::ROWS_MOVED],
            $outcome->rowsMoved,
        );
        self::assertSame(
            self::SURVIVOR_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * A refused browser merge hands the sentence back on the same frame.
     *
     * @throws HilosException When the merge fails
     */
    public function testARefusedBrowserMergeHandsBackTheSentence(): void
    {
        $agent = new AccountMergeRouteTestAgent();
        $agent->refuseWith = new ValidationException('No such user: 12');

        $agent->onSignalAgent(
            new AgentSignalData(new AccountMergeSignalData(
                self::SURVIVOR_USER_ID,
                self::LOSER_USER_ID,
                self::ACCEPT_KEY,
            )),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeResult();
        self::assertSame(self::ACCEPT_KEY, $result->acceptKey);
        self::assertSame('No such user: 12', $result->outcome);
    }

    /**
     * Runs one merge command the way the daemon routes it.
     *
     * @param AbstractAgent $agent Library under test, wired or unwired
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Fate the operator named, or null when they named none
     * @throws HilosException When the command handler itself fails
     */
    private function sendCommand(
        AbstractAgent $agent,
        int $survivorId,
        int $loserId,
        ?PasswordFate $passwordFate = null,
    ): void {
        $payload = [
            AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
            AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
        ];
        if ($passwordFate !== null) {
            $payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] = $passwordFate->value;
        }

        $agent->onSignalCommand(
            new CommandRequestDTO('corr-1', CliCommands::ACCOUNT_MERGE, $payload),
            '',
            '',
        );
    }

    /**
     * Takes the one reply the agent queued and fails the test when it queued none or two.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        self::assertCount(1, $replies, 'Every merge answers the operator exactly once');

        return $replies[0];
    }

    /**
     * Reads the sentence an error reply carried.
     *
     * @return string The refusal, as it reaches the command line
     */
    private function refusal(): string
    {
        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk(), 'The merge went through when it should not have');

        $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        self::assertIsString($message);

        return $message;
    }

    /**
     * Takes the one result frame the browser path queued.
     *
     * @return AccountMergeResultSignalData What the library handed back to the project
     */
    private function consumeMergeResult(): AccountMergeResultSignalData
    {
        $results = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof AgentSignalData && $data->data instanceof AccountMergeResultSignalData) {
                $results[] = $data->data;
            }
        }

        self::assertCount(1, $results, 'A browser merge answers on exactly one frame');

        return $results[0];
    }

    /**
     * Attaches a sign-in-link address to an account and returns it.
     *
     * @param int $userId Owning user id
     * @return string The address that was written
     * @throws HilosException When the identity write fails
     */
    private function seedMagicLink(int $userId): string
    {
        $email = $this->uniqueEmail();
        $this->identities()->createMagicLinkIdentity($userId, $email);

        return $email;
    }

    /**
     * Attaches a password to an account and returns the address it is written on.
     *
     * @param int $userId Owning user id
     * @return string The address that was written
     * @throws HilosException When the identity write fails
     */
    private function seedPassword(int $userId): string
    {
        $email = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($userId, $email, self::SEED_PASSWORD);

        return $email;
    }

    /**
     * @return Identities The framework identity collection under the fixture context
     */
    private function identities(): Identities
    {
        return Hilos::$db->identities;
    }

    /**
     * @return string Unique lowercase address within this case
     */
    private function uniqueEmail(): string
    {
        $this->emailCounter++;

        return "merge-route-{$this->emailCounter}@example.test";
    }

    /**
     * Reads which account an address belongs to, past every in-memory collection.
     *
     * @param string $email Address to resolve
     * @return ?int Owning user id, or null when no row carries the address
     * @throws DatabaseException When the query fails
     */
    private static function identityOwner(string $email): ?int
    {
        Database::sql('SELECT `user_id` FROM `hilos_identity` WHERE `identifier` = ?', [$email]);
        $row = Database::row();

        return $row === null ? null : (int)$row['user_id'];
    }

    /**
     * Inserts a session row the way the handshake would have.
     *
     * @param string $token Session cookie token
     * @param int $userId Bound user id
     * @throws DatabaseException When the insert fails
     */
    private static function seedSession(string $token, int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_session` (`token`, `user_id`) VALUES (?, ?)',
            [$token, $userId],
        );
    }

    /**
     * Reads a session's bound user straight from the database.
     *
     * @param string $token Session cookie token
     * @return ?int Bound user id, or null when the session is anonymous or unknown
     * @throws DatabaseException When the query fails
     */
    private static function boundUserId(string $token): ?int
    {
        Database::sql('SELECT `user_id` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $row = Database::row();

        return $row === null || $row['user_id'] === null ? null : (int)$row['user_id'];
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 */
final class AccountMergeRouteTestDbContext extends HilosDbContext
{
}

/**
 * The framework half of the sessions library, standing in for a project's concrete subclass.
 *
 * A base rather than two copies because the case needs the SAME library twice - once with the
 * seams wired and once without - and the difference is exactly the seams.
 */
abstract class AccountMergeRouteTestHost extends AbstractSessionsLibraryAgent
{
}

/**
 * Sessions library with both merge seams wired, standing in for a project binding: it records
 * what each was asked instead of reading a project's own rows.
 */
final class AccountMergeRouteTestAgent extends AccountMergeRouteTestHost
{
    /** @var string Family name this fixture reports its own moved rows under */
    public const string ROW_FAMILY = 'notes';

    /** @var int Rows this fixture claims to have moved, under its own family name */
    public const int ROWS_MOVED = 3;

    /** @var ?array{int, int} Ids the vouching seam was asked about, or null when it was not asked */
    public ?array $vouchedFor = null;

    /** @var ?array{int, int} Ids the row-move seam was asked about, or null when it was not asked */
    public ?array $moved = null;

    /** @var ?ValidationException Refusal the vouching seam raises instead of allowing the merge */
    public ?ValidationException $refuseWith = null;

    /** @var bool Whether the row move fails, the way a project's write fails mid-transaction */
    public bool $failTheRowMove = false;

    /**
     * Records the question, or refuses the way a project refuses an unknown account.
     *
     * @param int $survivorUserId Survivor user id that would absorb the loser
     * @param int $loserUserId Loser user id that would be folded in
     * @throws ValidationException When the test asked this seam to refuse
     */
    protected function assertMergeable(int $survivorUserId, int $loserUserId): void
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->vouchedFor = [$survivorUserId, $loserUserId];
    }

    /**
     * Records the question and reports a fixed tally, or fails the way a project's write does.
     *
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @return array<string, int> The fixed tally this fixture reports
     * @throws ValidationException When the test asked this seam to fail mid-transaction
     */
    protected function applyAccountMerge(int $survivorUserId, int $loserUserId): array
    {
        if ($this->failTheRowMove) {
            throw new ValidationException('The project could not move its rows');
        }

        $this->moved = [$survivorUserId, $loserUserId];

        return [self::ROW_FAMILY => self::ROWS_MOVED];
    }
}

/**
 * Sessions library of a project that never wired the seams - the framework default, unchanged.
 */
final class AccountMergeRouteTestUnwiredAgent extends AccountMergeRouteTestHost
{
}
