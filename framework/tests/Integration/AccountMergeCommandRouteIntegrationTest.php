<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
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
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The agent side of the account:merge route and of the browser frame beside it (HIL-378, HIL-729).
 *
 * An integration case rather than a unit one for the reason
 * {@see ImpersonationCommandRouteIntegrationTest} gives: every branch below the wire name ends
 * in real identity rows moving inside a real transaction, and a case that faked the identities
 * would pin its own fake instead of the path an operator walks.
 *
 * What is pinned is everything the FRAMEWORK owns, which since HIL-1199 is the whole operation
 * bar one question. The guards - two ids that are the same, an id that names nobody, an account
 * already folded into another, two accounts that each hold a password and nobody saying which
 * stays - the transaction, the identity re-point, the device keys that hang on those ways in
 * (HIL-1132), the tombstone (a merge row and a closed sign-in), the password outcome read back
 * off the account and the loser's forced sign-out all live here. What a project answers is what
 * it keeps for a person, and what is pinned about
 * that is the SHAPE of the seam rather than any answer: it is reached after the framework's
 * refusals, it runs where a failure still rolls the merge back, and a project that never wired
 * it refuses instead of half-merging. A project's own refusal is pinned by its shape too: it
 * comes after the framework's.
 *
 * The browser half is the same core through another door, so it is driven here too: success,
 * refusal and password-choice branches answer the page's named handover frame rather than the
 * operator's socket.
 */
final class AccountMergeCommandRouteIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Survivor of every merge below. */
    private const int SURVIVOR_USER_ID = 11;

    /** Loser of every merge below. */
    private const int LOSER_USER_ID = 12;

    /** A third person, the one an account folded before any case below was folded into. */
    private const int THIRD_USER_ID = 13;

    /** An id no person carries. */
    private const int NOBODY_USER_ID = 99;

    /** Accept key standing in for the browser that submitted the admin-table action. */
    private const string ACCEPT_KEY = 'accept-1';

    /**
     * Id the library's own claims are registered under. A test process starts no library, and
     * the tombstone writes the person and the merge table under the claim the library declares.
     */
    private const string LIBRARY_ID = 'test-agent:merge-library';

    /** Precomputed so a case seeding two passwords does not pay bcrypt twice. */
    private const string SEED_PASSWORD = 'merge-route-secret-42';

    /** Public key a seeded device key carries; the ceremony never verifies it here. */
    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

    /**
     * @var list<string> Framework tables this case needs. `hilos_setting` is the one framework
     *     collection loaded eagerly, so mounting the context reaches for it. The people and their
     *     merges are asked whether two accounts may be merged at all, and the tombstone writes
     *     both (HIL-1199); the merge table's keys hold the people, so it comes after them and is
     *     dropped before them. A device key hangs on its anchor by a foreign key, so
     *     `hilos_passkey_credential` comes right after `hilos_identity` and is dropped before it
     *     (HIL-1132).
     */
    private const array TABLES = [
        'hilos_user',
        'hilos_user_merge',
        'hilos_identity',
        'hilos_passkey_credential',
        'hilos_session',
        'hilos_setting',
    ];

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
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Survivor'), (?, 'Loser'), (?, 'Third')",
            [self::SURVIVOR_USER_ID, self::LOSER_USER_ID, self::THIRD_USER_ID],
        );

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new AccountMergeRouteTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        OwnershipDeclaration::claimDb(AccountMergeRouteTestHost::class, self::LIBRARY_ID);
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::LIBRARY_ID);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::LIBRARY_ID));
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
     * A merge asks the project and reports the framework's count beside the project's map, and
     * leaves the loser tombstoned: a merge row into the survivor, and its sign-in closed.
     *
     * @throws HilosException When a seed, the merge, or a read-back fails
     */
    public function testAMergeAsksTheProjectReportsWhatMovedAndTombstonesTheLoser(): void
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
        self::assertSame(self::SURVIVOR_USER_ID, self::survivorOf(self::LOSER_USER_ID));
        self::assertTrue(self::isBlocked(self::LOSER_USER_ID));
        self::assertFalse(self::isBlocked(self::SURVIVOR_USER_ID));
        self::assertNull(self::survivorOf(self::SURVIVOR_USER_ID), 'The survivor is not folded');
    }

    /**
     * A merge hands the loser's device key to the survivor: the row, the handle lookup, the
     * profile list and the short path all name the survivor, and the anchor is counted with the
     * ways in.
     *
     * @throws HilosException When a seed, the merge, or a read-back fails
     */
    public function testAMergeHandsTheLosersPasskeysToTheSurvivor(): void
    {
        [$credentialId, $userHandle] = $this->seedPasskey(self::LOSER_USER_ID);

        $this->sendCommand(new AccountMergeRouteTestAgent(), self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk(), 'A wired merge answers ok');
        self::assertSame(1, $reply->payload[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED]);
        self::assertSame(self::SURVIVOR_USER_ID, self::passkeyOwner($credentialId));
        self::assertSame(
            self::SURVIVOR_USER_ID,
            Hilos::$db->passkeyCredentials->findUserByUserHandle($userHandle),
        );
        $survivorKeys = Hilos::$db->passkeyCredentials->listByUser(self::SURVIVOR_USER_ID);
        self::assertCount(1, $survivorKeys);
        self::assertSame($credentialId, $survivorKeys[0]->credentialId);
        self::assertSame((string)self::SURVIVOR_USER_ID, $survivorKeys[0]->storedSetTop());
        self::assertSame([], Hilos::$db->passkeyCredentials->listByUser(self::LOSER_USER_ID));
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
     * An id that names nobody is refused as such, the survivor asked first, and the project is
     * not asked anything.
     *
     * @throws HilosException When the merge fails
     */
    public function testAnIdThatNamesNobodyIsRefusedAsSuch(): void
    {
        $agent = new AccountMergeRouteTestAgent();

        $this->sendCommand($agent, self::NOBODY_USER_ID, self::NOBODY_USER_ID + 1);
        self::assertSame('No such user: ' . self::NOBODY_USER_ID, $this->refusal());

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::NOBODY_USER_ID);
        self::assertSame('No such user: ' . self::NOBODY_USER_ID, $this->refusal());

        self::assertNull($agent->vouchedFor, 'The project is asked only about two accounts that may be merged');
        self::assertNull($agent->moved);
    }

    /**
     * A survivor that was itself folded away is refused, and so is a loser folded already; the
     * refusal writes nothing.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAnAccountFoldedAlreadyIsRefusedOnEitherSide(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        self::seedMerge(self::SURVIVOR_USER_ID, self::THIRD_USER_ID);
        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);
        self::assertSame('Survivor ' . self::SURVIVOR_USER_ID . ' is itself a merged account', $this->refusal());

        self::seedMerge(self::LOSER_USER_ID, self::THIRD_USER_ID);
        $this->sendCommand($agent, self::THIRD_USER_ID, self::LOSER_USER_ID);
        self::assertSame('Loser ' . self::LOSER_USER_ID . ' is already merged', $this->refusal());

        self::assertNull($agent->vouchedFor);
        self::assertNull($agent->moved);
        self::assertSame(self::THIRD_USER_ID, self::survivorOf(self::LOSER_USER_ID), 'The first merge stands');
        self::assertSame(
            self::LOSER_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * A project's own refusal comes after the framework's: it is asked only about two accounts
     * the framework let through, answers the operator once, and nothing moves.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAProjectRefusalComesAfterTheFrameworksAndMovesNothing(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();
        $agent->refuseWith = new ValidationException('This project keeps these two apart');

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::NOBODY_USER_ID);
        self::assertSame('No such user: ' . self::NOBODY_USER_ID, $this->refusal());

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);
        self::assertSame('This project keeps these two apart', $this->refusal());

        self::assertNull($agent->moved, 'A refusal never reaches the row move');
        self::assertNull(self::survivorOf(self::LOSER_USER_ID));
        self::assertSame(
            self::LOSER_USER_ID,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail)?->userId,
        );
    }

    /**
     * A project that never wired the merge refuses rather than half-merging - after the
     * framework's refusals, so an id that names nobody is still refused as such.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAnUnwiredProjectRefusesEveryMergeAndWritesNothing(): void
    {
        $loserEmail = $this->seedMagicLink(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestUnwiredAgent();

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::NOBODY_USER_ID);
        self::assertSame('No such user: ' . self::NOBODY_USER_ID, $this->refusal());

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);
        self::assertSame('Account merge is not wired in this project', $this->refusal());

        self::assertSame(self::LOSER_USER_ID, self::identityOwner($loserEmail));
        self::assertNull(self::survivorOf(self::LOSER_USER_ID));
        self::assertFalse(self::isBlocked(self::LOSER_USER_ID));
    }

    /**
     * The passwords are weighed only after the framework and the project let both accounts
     * through.
     *
     * The order is the whole point: an account that cannot be merged must be refused as such
     * rather than as a password question.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testTwoPasswordsAreWeighedOnlyAfterTheAccountsMayBeMerged(): void
    {
        $this->seedPassword(self::SURVIVOR_USER_ID);
        $this->seedPassword(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::NOBODY_USER_ID);
        self::assertSame('No such user: ' . self::NOBODY_USER_ID, $this->refusal());

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
     * The project's row move runs inside the transaction: its failure undoes the re-point, and
     * no tombstone is written.
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
        self::assertNull(self::survivorOf(self::LOSER_USER_ID));
        self::assertFalse(self::isBlocked(self::LOSER_USER_ID));
    }

    /**
     * A project's row move that fails rolls the device key back with the ways in: the database
     * still names the loser. Read past the collection, whose object cache can keep the survivor.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testAFailingRowMoveLeavesThePasskeysWithTheLoser(): void
    {
        [$credentialId] = $this->seedPasskey(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();
        $agent->failTheRowMove = true;

        $this->sendCommand($agent, self::SURVIVOR_USER_ID, self::LOSER_USER_ID);

        self::assertSame('The project could not move its rows', $this->refusal());
        self::assertSame(self::LOSER_USER_ID, self::passkeyOwner($credentialId));
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
            new AgentSignalData($this->browserRequest()),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeAnswer();
        self::assertSame(self::ACCEPT_KEY, $result->acceptKey);
        self::assertSame(
            'Merged #12 into #11. Moved: sign-in methods 1, notes 3.',
            $result->successMessage,
        );
        self::assertNull($result->error);
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
        self::seedMerge(self::LOSER_USER_ID, self::THIRD_USER_ID);

        $agent->onSignalAgent(
            new AgentSignalData($this->browserRequest()),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeAnswer();
        self::assertSame(self::ACCEPT_KEY, $result->acceptKey);
        self::assertSame('Loser 12 is already merged', $result->error);
        self::assertNull($result->successMessage);
    }

    /**
     * Browser wording asks for a choice without leaking the CLI flag syntax.
     *
     * @throws HilosException When a seed or the merge fails
     */
    public function testBrowserMergeWithTwoPasswordsAndNoFateAsksForAChoice(): void
    {
        $this->seedPassword(self::SURVIVOR_USER_ID);
        $this->seedPassword(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $agent->onSignalAgent(
            new AgentSignalData($this->browserRequest()),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeAnswer();
        self::assertSame(AbstractSessionsLibraryAgent::ACCOUNT_MERGE_PASSWORD_FATE_REQUIRED_MESSAGE, $result->error);
        self::assertNull($agent->moved);
    }

    /**
     * A browser password choice reaches the shared identity re-point.
     *
     * @throws HilosException When a seed, the merge, or a read-back fails
     */
    public function testBrowserMergeWithANamedFateCompletes(): void
    {
        $survivorEmail = $this->seedPassword(self::SURVIVOR_USER_ID);
        $this->seedPassword(self::LOSER_USER_ID);
        $agent = new AccountMergeRouteTestAgent();

        $agent->onSignalAgent(
            new AgentSignalData($this->browserRequest(PasswordFate::SURVIVOR)),
            '',
            HilosSignalConstants::HILOS_ACCOUNT_MERGE,
        );

        $result = $this->consumeMergeAnswer();
        self::assertNull($result->error);
        self::assertSame($survivorEmail, $this->identities()->findPasswordByUser(self::SURVIVOR_USER_ID)?->identifier);
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
     * Builds the browser's handed-over merge request.
     *
     * @param ?PasswordFate $passwordFate Fate named by the browser, or null
     * @return AccountMergeSignalData Request addressed back to the test page
     */
    private function browserRequest(?PasswordFate $passwordFate = null): AccountMergeSignalData
    {
        return new AccountMergeSignalData(
            survivorUserId: self::SURVIVOR_USER_ID,
            loserUserId: self::LOSER_USER_ID,
            passwordFate: $passwordFate?->value,
            replySignal: HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'request-1',
            action: HilosSignalConstants::HILOS_USER_MERGE,
            successMessage: null,
        );
    }

    /**
     * Takes the one handover answer the browser path queued.
     *
     * @return HandoverAnswerSignalData What the library handed back to the page
     */
    private function consumeMergeAnswer(): HandoverAnswerSignalData
    {
        $results = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE
                && $data instanceof AgentSignalData
                && $data->data instanceof HandoverAnswerSignalData
            ) {
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
     * Registers a device key the way the ceremony does, and hands back its id and handle.
     *
     * @param int $userId Person the key is stored for
     * @return array{string, string} Credential id and the user handle stored on the row
     * @throws HilosException When the anchor or the key cannot be stored
     */
    private function seedPasskey(int $userId): array
    {
        $credentialId = RandomHelper::hex(16);
        $userHandle = RandomHelper::hex(16);
        $identityId = $this->identities()->createPasskeyIdentity($userId, $credentialId)->id;
        self::assertNotNull($identityId);

        Hilos::$db->passkeyCredentials->createFromRegistration(
            $identityId,
            $userId,
            $credentialId,
            self::PUBLIC_KEY_PEM,
            PasskeyAlgorithm::Es256,
            0,
            null,
            null,
            $userHandle,
            null,
        );

        return [$credentialId, $userHandle];
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
     * Reads which account a device key belongs to, past every in-memory collection.
     *
     * @param string $credentialId Credential id to resolve
     * @return ?int Owning user id, or null when no row carries the id
     * @throws DatabaseException When the query fails
     */
    private static function passkeyOwner(string $credentialId): ?int
    {
        Database::sql(
            'SELECT `user_id` FROM `hilos_passkey_credential` WHERE `credential_id` = ?',
            [$credentialId],
        );
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
     * Folds one account into another past the library, the way an earlier merge left it.
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
     * Reads which account one was folded into, straight from the database.
     *
     * @param int $userId Account to look up
     * @return ?int Survivor it was folded into, or null when it was never folded
     * @throws DatabaseException When the query fails
     */
    private static function survivorOf(int $userId): ?int
    {
        Database::sql('SELECT `survivor_user_id` FROM `hilos_user_merge` WHERE `user_id` = ?', [$userId]);
        $row = Database::row();

        return $row === null ? null : (int)$row['survivor_user_id'];
    }

    /**
     * Reads a person's block flag straight from the database.
     *
     * @param int $userId Person to look up
     * @return bool Whether the person's sign-in is closed
     * @throws DatabaseException When the query fails
     */
    private static function isBlocked(int $userId): bool
    {
        Database::sql('SELECT `block` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row, "Person #{$userId} is seeded by every case");

        return (bool)$row['block'];
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * The drop runs in reverse order: a table whose foreign key holds an earlier one goes first.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
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
 * merge wired and once without - and the difference is exactly the seams.
 */
abstract class AccountMergeRouteTestHost extends AbstractSessionsLibraryAgent
{
}

/**
 * Sessions library with the merge wired and a check of its own, standing in for a project
 * binding: it records what it was asked instead of reading a project's own rows.
 */
final class AccountMergeRouteTestAgent extends AccountMergeRouteTestHost
{
    /** @var string Family name this fixture reports its own moved rows under */
    public const string ROW_FAMILY = 'notes';

    /** @var int Rows this fixture claims to have moved, under its own family name */
    public const int ROWS_MOVED = 3;

    /** @var ?array{int, int} Ids the project's own check was asked about, or null when it was not asked */
    public ?array $vouchedFor = null;

    /** @var ?array{int, int} Ids the row-move seam was asked about, or null when it was not asked */
    public ?array $moved = null;

    /** @var ?ValidationException Refusal the project's own check raises instead of allowing the merge */
    public ?ValidationException $refuseWith = null;

    /** @var bool Whether the row move fails, the way a project's write fails mid-transaction */
    public bool $failTheRowMove = false;

    /**
     * Asks the framework first, then records the question or refuses the way a project with a
     * refusal of its own does.
     *
     * @param int $survivorUserId Survivor user id that would absorb the loser
     * @param int $loserUserId Loser user id that would be folded in
     * @throws ValidationException When the framework refuses, or the test asked this check to refuse
     * @throws HilosException When the framework cannot read the two accounts
     */
    protected function assertMergeable(int $survivorUserId, int $loserUserId): void
    {
        parent::assertMergeable($survivorUserId, $loserUserId);

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
 * Sessions library of a project that never wired the merge - the framework default, unchanged.
 */
final class AccountMergeRouteTestUnwiredAgent extends AccountMergeRouteTestHost
{
}
