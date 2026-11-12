<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorTrustRestrictionSweep;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Log\AgentLogStream;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use ReflectionProperty;

/**
 * A trust restriction reaches the trusts of people whose agents sleep (HIL-1901).
 *
 * The table answers who still owes the application of a restriction: a person holding a trust
 * granted no later than a restriction was saved that runs past the restriction's limit, or any such
 * trust under a restriction of 0 days. The person's agent applies every stored restriction when it
 * starts, without a frame and without an answer, and a frame for a restriction that arrives after
 * the start finds nothing left to change.
 *
 * The sessions holder's sweep asks the people who still owe a restriction, a portion at a time and
 * in id order, and ends its pass once nobody is left to ask and no frame is left to wait for: an
 * answer, a minute of silence or the agent's end each take a person off. The next pass starts an
 * hour after the last one ended, and a quiet minute reads nothing; a request for one restriction
 * starts a pass at once or winds the running one back, and is answered with what is still owed
 * under the restrictions up to it. The frames of a scheduled pass carry one receipt, opened by
 * the first tick that has somebody to ask; the frames of a request carry the requester's.
 *
 * The settings library records the restrictions in its own frame, and the person's agent applies
 * them in its frame and under the claims its class declares. The sweep is built over the holder
 * with a portion of two, and the case carries its frames to the people's agents and their answers
 * back. Trusts and people are stored as rows, moments are named relative to now - the sweep's
 * ticks are given theirs as numbers - and nothing waits for the clock.
 */
final class SecondFactorTrustRestrictionSweepIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Reply name a sender of the restriction frame registers for itself. */
    private const string REPLY_SIGNAL = 'integration_trust_restrict_sweep_done';

    private const int DAY_MS = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

    private const int HOUR_MS = TimeConstants::SECONDS_PER_HOUR * TimeConstants::MS_PER_SECOND;

    private const int SESSION_CREATED_AT = 1791626400000;

    private const string START_FAILURE = 'not applied at start';

    /** Name of the holder's cron rule of the sweep. */
    private const string SWEEP_RULE = 'hilos_second_factor_trust_restriction_sweep';

    /** Frames of one pass awaiting an answer at a time, small enough to need a second portion. */
    private const int PORTION = 2;

    /** Step between two ticks of a case that are not a minute apart. */
    private const int SECOND_MS = TimeConstants::MS_PER_SECOND;

    private const int MINUTE_MS = TimeConstants::SECONDS_PER_MINUTE * TimeConstants::MS_PER_SECOND;

    /** Why a person's agent refused in a case: the message of the failure held back. */
    private const string REFUSAL_DETAIL = 'Trust storage failure';

    /** Journal tables, children first, as a case raises and drops them. */
    private const array JOURNAL_TABLES = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private SettingsLibraryAgent $settings;

    private TrustRestrictionSweepTestSessionsLibrary $sessions;

    private ?SignalRouter $previousRouter = null;

    /** @var class-string<Hilos> Project facade bound before the case */
    private string $previousApp;

    private ?RtContext $previousRt = null;

    /** Directory the agents' log streams of the case are written to. */
    private string $logDirectory = '';

    /** Whether the case raised the journal tables, which are dropped again after it. */
    private bool $journalRaised = false;

    /** Whether the case bound the sign-in project and its runtime. */
    private bool $signInProjectBound = false;

    /** Browsers seeded so far, which names the next one's token. */
    private int $browsers = 0;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $this->settings = new SettingsLibraryAgent();
        $this->sessions = new TrustRestrictionSweepTestSessionsLibrary();
        OwnershipDeclaration::claimDb($this->settings::class, $this->settings->getId());
        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-trust-restriction-sweep-log');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/daemon.log');
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->settings->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->settings->getId()));
        Logger::resetLogFile();
        foreach (glob($this->logDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->logDirectory);
        if ($this->signInProjectBound) {
            foreach (self::holderCollections() as $collection) {
                RtTruthSourceRegistry::unregisterDaemon($collection);
            }
            SourceChangeBus::reset();
        }
        if ($this->journalRaised) {
            $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            foreach (self::JOURNAL_TABLES as $table) {
                Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
            }
        }
        Hilos::$rt = $this->previousRt;
        self::bindAppClass($this->previousApp);
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * People come by id, each once, after the id asked and outside the listed ones, and fewer than
     * asked means nobody owes beyond them. A page cut inside one person's trusts hides nobody after.
     *
     * @throws HilosException When a fixture row cannot be written or a lookup fails
     */
    public function testOwingPeopleComeInIdOrderEachOncePastAPageCutInsideOnePerson(): void
    {
        $now = TimeHelper::nowMs();
        $manyBrowsers = self::seedPerson();
        $oneBrowser = self::seedPerson();
        $withinTheLimit = self::seedPerson();
        $grantedAfter = self::seedPerson();
        $afterTheRevocation = self::seedPerson();
        $last = self::seedPerson();
        foreach ([1, 2, 3] as $hours) {
            $this->trust($manyBrowsers, $now - 2 * self::DAY_MS - $hours * self::HOUR_MS, $now + 28 * self::DAY_MS);
        }
        $this->trust($oneBrowser, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $this->trust($withinTheLimit, $now - 2 * self::DAY_MS, $now + 5 * self::DAY_MS);
        $this->trust($grantedAfter, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $this->trust($afterTheRevocation, $now - 3 * self::DAY_MS, $now + self::DAY_MS);
        $this->trust($last, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $this->record($now - 4 * self::DAY_MS, 0);
        $this->record($now - self::DAY_MS, 7);
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();

        self::assertSame([$manyBrowsers, $oneBrowser], $trusts->owingPeople(0, [], 2));
        self::assertSame([$manyBrowsers, $oneBrowser, $last], $trusts->owingPeople(0, [], 10));
        self::assertSame([$oneBrowser], $trusts->owingPeople($manyBrowsers, [], 1));
        self::assertSame([$last], $trusts->owingPeople($manyBrowsers, [$oneBrowser], 10));
        self::assertSame([], $trusts->owingPeople($last, [], 10));
        self::assertTrue($trusts->owes($manyBrowsers));
        self::assertFalse($trusts->owes($withinTheLimit));
        self::assertFalse($trusts->owes($grantedAfter));
        self::assertFalse($trusts->owes($afterTheRevocation));
    }

    /**
     * A restriction of 0 days owes the trusts granted no later than it was saved whatever their end,
     * one that ended before the save and one granted at the very moment of it included, and leaves
     * out the one granted a millisecond after.
     *
     * @throws HilosException When a fixture row cannot be written or a lookup fails
     */
    public function testARestrictionOfZeroDaysOwesEveryTrustGrantedBeforeIt(): void
    {
        $now = TimeHelper::nowMs();
        $savedAt = $now - self::DAY_MS;
        $before = self::seedPerson();
        $ended = self::seedPerson();
        $atTheSave = self::seedPerson();
        $after = self::seedPerson();
        $this->trust($before, $now - 2 * self::DAY_MS, $now + self::HOUR_MS);
        $this->trust($ended, $now - 3 * self::DAY_MS, $savedAt - self::HOUR_MS);
        $this->trust($atTheSave, $savedAt, $now + 29 * self::DAY_MS);
        $this->trust($after, $savedAt + 1, $now + 29 * self::DAY_MS);
        $revoking = $this->record($savedAt, 0);
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();

        self::assertSame([$before, $ended, $atTheSave], $trusts->owingPeople(0, [], 10));
        self::assertSame(3, $trusts->countOwing($revoking));
        self::assertTrue($trusts->owes($before));
        self::assertTrue($trusts->owes($ended));
        self::assertTrue($trusts->owes($atTheSave));
        self::assertFalse($trusts->owes($after));
    }

    /**
     * A count through one restriction leaves out the trusts only a later one breaks.
     *
     * @throws HilosException When a fixture row cannot be written or a lookup fails
     */
    public function testCountingThroughARestrictionLeavesOutTheLaterOnes(): void
    {
        $restrictions = Hilos::$db->secondFactorTrustRestrictions->getObjectCollection();
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();
        self::assertNull($restrictions->latestId());

        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $this->trust($userId, $now - 3 * self::DAY_MS, $now + 26 * self::DAY_MS);
        $loose = $this->record($now - 2 * self::DAY_MS, 30);
        $tight = $this->record($now - self::DAY_MS, 7);

        self::assertSame($tight, $restrictions->latestId());
        self::assertSame(0, $trusts->countOwing($loose));
        self::assertSame(2, $trusts->countOwing($tight));
        self::assertSame(2, $trusts->countOwing($tight + 1));
        self::assertSame(0, $trusts->countOwing($loose - 1));
    }

    /**
     * @throws HilosException When the lookup is not refused
     */
    public function testOwingPeopleAreAskedForInAPositiveNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Hilos::$db->secondFactorTrusts->getObjectCollection()->owingPeople(0, [], 0);
    }

    /**
     * A person whose agent sleeps owes the restriction until the frame wakes the agent, and nothing after it.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testTheFrameToASleepingOwnerAppliesTheRestrictionAndNothingIsOwedAfter(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $cut = $this->trust($userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $this->trust($userId, $now - 5 * self::DAY_MS, $now + 25 * self::DAY_MS);
        $this->record($now - 4 * self::DAY_MS, 0);
        $savedAt = $now - self::DAY_MS;
        $restrictionId = $this->record($savedAt, 7);
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();
        self::assertSame(2, $trusts->countOwing($restrictionId));
        self::assertTrue($trusts->owes($userId));

        self::assertNull($this->apply($userId, $restrictionId)->error);

        self::assertSame([$cut => [$now - 2 * self::DAY_MS, $savedAt + 7 * self::DAY_MS]], self::trustRows($userId));
        self::assertSame(0, $trusts->countOwing($restrictionId));
        self::assertFalse($trusts->owes($userId));
    }

    /**
     * The agent's start cuts and takes out what it owes without being asked and answers nobody; the
     * frame that woke it then changes nothing and is answered ok.
     *
     * @throws HilosException When a fixture row cannot be written or the agent fails
     */
    public function testTheStartAppliesTheRestrictionsWithoutAFrameOrAnAnswer(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $cut = $this->trust($userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $this->trust($userId, $now - 5 * self::DAY_MS, $now + 25 * self::DAY_MS);
        $kept = $this->trust($userId, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $this->record($now - 4 * self::DAY_MS, 0);
        $savedAt = $now - self::DAY_MS;
        $restrictionId = $this->record($savedAt, 7);
        $applied = [
            $cut => [$now - 2 * self::DAY_MS, $savedAt + 7 * self::DAY_MS],
            $kept => [$now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS],
        ];

        self::assertSame([], $this->start($userId));

        self::assertSame($applied, self::trustRows($userId));
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $queued->data, 'The start sends no frame to an agent');
        }
        self::assertFalse(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($userId));
        self::assertNull($this->apply($userId, $restrictionId)->error);
        self::assertSame($applied, self::trustRows($userId));
    }

    /**
     * With nothing owed the start opens no transaction: one the case holds open would refuse it, and
     * the refusal would be logged. A person with no trusts - erased, or never trusted a browser - owes nothing too.
     *
     * @throws HilosException When a fixture row cannot be written or the agent fails
     */
    public function testAStartWithNothingOwedWritesNothingAndOpensNoTransaction(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $withoutTrusts = self::seedPerson();
        $this->trust($userId, $now - 2 * self::DAY_MS, $now + 5 * self::DAY_MS);
        $this->trust($userId, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $this->record($now - self::DAY_MS, 7);
        $before = self::trustRows($userId);

        Database::transactionStart();
        try {
            self::assertSame([], $this->start($userId));
            self::assertSame([], $this->start($withoutTrusts));
        } finally {
            Database::transactionRollback();
        }

        self::assertSame($before, self::trustRows($userId));
        self::assertSame([], self::trustRows($withoutTrusts));
    }

    /**
     * A write that fails at the start takes every row back and is logged under the person; the start
     * itself does not fail.
     *
     * @throws HilosException When a fixture row cannot be written or the agent fails
     */
    public function testAFailedWriteAtTheStartIsLoggedAndTheStartGoesOn(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $cut = $this->trust($userId, $now - self::DAY_MS, $now + 29 * self::DAY_MS);
        $removed = $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        self::assertGreaterThan($cut, $removed, 'The cut row is written before the removed one');
        $this->record($now - 2 * self::DAY_MS, 0);
        $this->record($now - 12 * self::HOUR_MS, 7);
        $before = self::trustRows($userId);
        Database::sqlRun("CREATE TRIGGER refuse_test_trust_delete BEFORE DELETE ON hilos_second_factor_trust
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trust storage failure'");
        try {
            $errors = $this->start($userId);
        } finally {
            Database::sqlRun('DROP TRIGGER refuse_test_trust_delete');
        }

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Trust restrictions of #' . $userId . ' ' . self::START_FAILURE . ': ', $errors[0]);
        self::assertSame($before, self::trustRows($userId));
        self::assertFalse(Database::hasOpenTransactions());
        self::assertTrue(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes($userId));
    }

    /**
     * An account folded into another one still applies the restrictions to the trusts it has left.
     *
     * @throws HilosException When a fixture row cannot be written or the agent fails
     */
    public function testAFoldedAccountAppliesTheRestrictionsAtItsStart(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $survivorId = self::seedPerson();
        $trust = $this->trust($userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $savedAt = $now - self::DAY_MS;
        $this->record($savedAt, 7);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorId, $now - self::HOUR_MS],
        );

        self::assertSame([], $this->start($userId));

        self::assertSame([$trust => [$now - 2 * self::DAY_MS, $savedAt + 7 * self::DAY_MS]], self::trustRows($userId));
    }

    /**
     * A tick asks the people who owe in id order, a portion at a time, naming the last restriction
     * saved and the holder's answer; the next portion goes on the next tick, and a person whose
     * trust breaks nothing is not asked.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testATickAsksTheOwingPeopleInIdOrderAPortionAtATime(): void
    {
        $now = TimeHelper::nowMs();
        $first = self::seedPerson();
        $second = self::seedPerson();
        $withinTheLimit = self::seedPerson();
        $third = self::seedPerson();
        foreach ([$first, $second, $third] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $this->trust($withinTheLimit, $now - 3 * self::DAY_MS, $now + 2 * self::DAY_MS);
        $this->record($now - 2 * self::DAY_MS, 30);
        $latest = $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();

        $sweep->cron($now, self::sweepRule());

        $frames = $this->restrictionFrames();
        self::assertSame([$first, $second], self::askedPeople($frames));
        foreach ($frames as $frame) {
            self::assertSame($latest, self::ask($frame)->restrictionId);
            self::assertSame(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE, self::ask($frame)->replySignal);
        }
        $this->carry($sweep, $frames);

        $sweep->cron($now + self::SECOND_MS, self::sweepRule());

        self::assertSame([$third], self::askedPeople($this->restrictionFrames()));
    }

    /**
     * A pass ends with its answers, and the frame it sent applied the restriction to the sleeping
     * owners. Within the hour after a pass ended a tick asks nobody, not even a person who came to
     * owe meanwhile, since it reads nothing, and opens no receipt; an hour after the end a new pass
     * asks that person under a receipt of its own.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testAPassEndsWithItsAnswersAndTheNextStartsAnHourAfter(): void
    {
        $now = TimeHelper::nowMs();
        $first = self::seedPerson();
        $second = self::seedPerson();
        foreach ([$first, $second] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $savedAt = $now - self::DAY_MS;
        $restrictionId = $this->record($savedAt, 7);
        $this->raiseJournal();
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();
        $sweep = $this->sweep();

        $sweep->cron($now, self::sweepRule());
        $this->carry($sweep, $this->restrictionFrames());
        $ended = $now + self::SECOND_MS;
        $sweep->cron($ended, self::sweepRule());

        self::assertSame([], $this->restrictionFrames());
        self::assertSame(0, $trusts->countOwing($restrictionId));
        self::assertSame([[$now - 3 * self::DAY_MS, $savedAt + 7 * self::DAY_MS]], array_values(self::trustRows($first)));
        $receipts = self::sweepReceipts();
        self::assertCount(1, $receipts, 'The pass opened one receipt and its end closed it');

        $latecomer = self::seedPerson();
        $this->trust($latecomer, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        self::assertTrue($trusts->owes($latecomer));
        foreach ([self::SECOND_MS, self::MINUTE_MS, self::HOUR_MS - 1] as $after) {
            $sweep->cron($ended + $after, self::sweepRule());
            self::assertSame([], $this->restrictionFrames(), 'No pass starts ' . $after . ' ms after the last one ended');
            self::assertSame($receipts, self::sweepReceipts(), 'A tick ' . $after . ' ms after the end opens no receipt');
        }

        $sweep->cron($ended + self::HOUR_MS, self::sweepRule());

        self::assertSame([$latecomer], self::askedPeople($this->restrictionFrames()));
        self::assertCount(2, self::sweepReceipts(), 'The pass of the latecomer opens a receipt of its own');
    }

    /**
     * A tick that finds nobody left to ask opens no receipt of the rule, though the portion has room
     * and the pass still waits for an answer: the receipt is opened by the first portion that has
     * somebody in it. A pass that finds nobody owing an hour after the last one leaves none behind.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testATickWithNobodyLeftToAskOpensNoReceipt(): void
    {
        $now = TimeHelper::nowMs();
        $first = self::seedPerson();
        $second = self::seedPerson();
        foreach ([$first, $second] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $this->raiseJournal();
        $sweep = $this->sweep();
        $sweep->await($restrictionId, static function (): void {
        });
        $requested = $this->restrictionFrames();
        self::assertSame([$first, $second], self::askedPeople($requested));
        $this->carry($sweep, [$requested[0]]);

        $sweep->cron($now, self::sweepRule());

        self::assertSame([], $this->restrictionFrames());
        self::assertSame([], self::sweepReceipts(), 'A tick with nobody left to ask opens no receipt');
        $this->carry($sweep, [$requested[1]]);
        $ended = $now + self::SECOND_MS;
        $sweep->cron($ended, self::sweepRule());

        $sweep->cron($ended + self::HOUR_MS, self::sweepRule());

        self::assertSame([], $this->restrictionFrames());
        self::assertSame([], self::sweepReceipts(), 'A pass with nobody owing leaves no receipt');
    }

    /**
     * A person who does not answer within a minute is dropped from the pass with a line in the
     * holder's log, and the pass ends without them; the answer that comes after is passed over,
     * and the next pass asks the person again.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testASilentOwnerIsDroppedAfterAMinuteAndAskedAgainByTheNextPass(): void
    {
        $now = TimeHelper::nowMs();
        $answering = self::seedPerson();
        $silent = self::seedPerson();
        foreach ([$answering, $silent] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();
        $sweep->cron($now, self::sweepRule());
        $frames = $this->restrictionFrames();
        self::assertSame([$answering, $silent], self::askedPeople($frames));
        $this->carry($sweep, [$frames[0]]);

        $sweep->cron($now + self::MINUTE_MS - 1, self::sweepRule());
        self::assertStringNotContainsString('#' . $silent, $this->holderLog());

        $ended = $now + self::MINUTE_MS;
        $sweep->cron($ended, self::sweepRule());

        self::assertStringContainsString("Trust restriction not confirmed by #{$silent} within a minute", $this->holderLog());
        $sweep->answered(self::refusal($frames[1]));
        self::assertStringNotContainsString('refused', $this->holderLog(), 'The late answer is passed over');
        $sweep->cron($ended + self::HOUR_MS - 1, self::sweepRule());
        self::assertSame([], $this->restrictionFrames());

        $sweep->cron($ended + self::HOUR_MS, self::sweepRule());

        self::assertSame([$silent], self::askedPeople($this->restrictionFrames()));
    }

    /**
     * A refusal is logged under the holder, its person is not asked again in the same pass, and
     * whoever waits for the restriction hears that trusts are still owed; the next pass, an hour
     * after this one ended, asks the person again.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testARefusingOwnerIsLoggedLeftForTheNextPassAndTheWaiterHearsOfIt(): void
    {
        $now = TimeHelper::nowMs();
        $refusing = self::seedPerson();
        $second = self::seedPerson();
        $third = self::seedPerson();
        foreach ([$refusing, $second, $third] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();
        $settled = [];
        $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });
        $frames = $this->restrictionFrames();
        self::assertSame([$refusing, $second], self::askedPeople($frames));

        $sweep->answered(self::refusal($frames[0]));
        $this->carry($sweep, [$frames[1]]);
        $sweep->cron($now, self::sweepRule());
        $last = $this->restrictionFrames();
        self::assertSame([$third], self::askedPeople($last));
        $before = TimeHelper::nowMs();
        $this->carry($sweep, $last);
        $after = TimeHelper::nowMs();

        self::assertSame([1], $settled);
        self::assertStringContainsString(
            "Trust restriction {$restrictionId} for #{$refusing} refused: " . self::REFUSAL_DETAIL,
            $this->holderLog(),
        );
        $sweep->cron($before + self::HOUR_MS - 1, self::sweepRule());
        self::assertSame([], $this->restrictionFrames());

        $sweep->cron($after + self::HOUR_MS, self::sweepRule());

        self::assertSame([$refusing], self::askedPeople($this->restrictionFrames()));
    }

    /**
     * A person whose agent is gone is dropped at once, and the pass ends without the minute of
     * silence; an agent of another kind under the same index is not that person's.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testAGoneAgentIsDroppedAtOnceAndThePassEndsWithoutWaiting(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();
        $settled = [];
        $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });
        self::assertSame([$userId], self::askedPeople($this->restrictionFrames()));

        $sweep->gone(new AgentsGoneSignalData(
            [TrustRestrictionSweepTestSessionsLibrary::AGENT_TYPE . AgentConstants::ID_SEPARATOR . $userId],
            AgentsGoneSignalData::REASON_START_FAILED,
        ));
        self::assertSame([], $settled);

        $sweep->gone(new AgentsGoneSignalData(
            [HilosAgentType::HILOS_USER . AgentConstants::ID_SEPARATOR . $userId],
            AgentsGoneSignalData::REASON_START_FAILED,
        ));

        self::assertSame([1], $settled);
        self::assertStringContainsString(
            "Trust restriction not confirmed by #{$userId}, its agent gone: " . AgentsGoneSignalData::REASON_START_FAILED,
            $this->holderLog(),
        );
    }

    /**
     * A request with no pass running sends its portion at once, past the hour between passes, and
     * hears 0 once every owner answered; with nothing owed it hears 0 before the request returns,
     * and nobody is asked.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testARequestStartsAPassAtOnceAndHearsZeroWhenNothingIsLeft(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();
        $sweep->cron($now - self::SECOND_MS, self::sweepRule());
        $this->carry($sweep, $this->restrictionFrames());
        $settled = [];

        $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });

        self::assertSame([], $this->restrictionFrames(), 'Nothing is owed, so nobody is asked');
        self::assertSame([0], $settled, 'Settled before the request returned');

        $this->trust($userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $settled = [];

        $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });

        self::assertSame([], $settled);
        $this->carry($sweep, $this->restrictionFrames());
        self::assertSame([0], $settled);
    }

    /**
     * Every request waiting for a pass is answered when it ends, even when another one's callback fails.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testAFailingCallbackIsLoggedAndTheOtherRequestsAreAnswered(): void
    {
        $now = TimeHelper::nowMs();
        $userId = self::seedPerson();
        $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $sweep = $this->sweep();
        $settled = [];
        $sweep->await($restrictionId, static function (): void {
            throw new InvalidArgumentException('Waiter failure');
        });
        $sweep->await($restrictionId, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });
        $this->carry($sweep, $this->restrictionFrames());

        $sweep->cron($now, self::sweepRule());

        self::assertSame([0], $settled);
        self::assertStringContainsString('Waiter failure', $this->holderLog());
    }

    /**
     * @throws HilosException When the request is not refused
     */
    public function testARequestNamesAPositiveRestriction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sweep()->await(0, static function (): void {
        });
    }

    /**
     * A request during a pass winds it back: a person behind the place the pass reached who breaks
     * the new restriction is asked on the next tick, a person whose frame is still out is not asked
     * twice, and the request counts only the restrictions up to its own.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testARequestDuringAPassWindsItBackWithoutAskingTwice(): void
    {
        $now = TimeHelper::nowMs();
        $behind = self::seedPerson();
        $answered = self::seedPerson();
        $outstanding = self::seedPerson();
        $grantedLater = self::seedPerson();
        $this->trust($behind, $now - 3 * self::DAY_MS, $now + 3 * self::DAY_MS);
        $this->trust($answered, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $this->trust($outstanding, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $this->trust($grantedLater, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $this->record($now - 2 * self::DAY_MS, 7);
        $sweep = $this->sweep();
        $sweep->cron($now, self::sweepRule());
        $frames = $this->restrictionFrames();
        self::assertSame([$answered, $outstanding], self::askedPeople($frames));
        $this->carry($sweep, [$frames[0]]);
        $revoking = $this->record($now - self::DAY_MS, 0);
        $settled = [];

        $sweep->await($revoking, static function (int $owing) use (&$settled): void {
            $settled[] = $owing;
        });

        self::assertSame([], $this->restrictionFrames(), 'A running pass is wound back, not asked from the request');
        $asked = [];
        foreach ([1, 2, 3] as $seconds) {
            $sweep->cron($now + $seconds * self::SECOND_MS, self::sweepRule());
            $tick = $this->restrictionFrames();
            $asked = [...$asked, ...self::askedPeople($tick)];
            $this->carry($sweep, $tick);
        }
        self::assertSame([$behind, $answered], $asked);
        $tightening = $this->record($now - 6 * self::HOUR_MS, 1);
        self::assertSame([], $settled);

        $this->carry($sweep, [$frames[1]]);

        self::assertSame([0], $settled);
        self::assertSame(1, Hilos::$db->secondFactorTrusts->getObjectCollection()->countOwing($tightening));
    }

    /**
     * The holder arms the sweep on its start to run on the first tick, keeps the rule among its
     * schedules, sends the frames from that tick, hands the answers and the gone agents it is told
     * of to the sweep, and closes the receipt of the running pass on its stop.
     *
     * @throws HilosException When a fixture row cannot be written or the holder fails
     */
    public function testTheHolderRunsTheSweepFromItsScheduleAndHandsItItsFrames(): void
    {
        $now = TimeHelper::nowMs();
        $refusing = self::seedPerson();
        $fallen = self::seedPerson();
        $waited = self::seedPerson();
        foreach ([$refusing, $fallen, $waited] as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $this->raiseJournal();
        $this->bindSignInProject();
        $this->sessions->onStart();
        $rules = [];
        foreach ($this->sessions->cronRules() as $rule) {
            $rules[$rule->name] = $rule->expression;
        }
        self::assertSame('* * * * *', $rules[self::SWEEP_RULE] ?? null);

        $this->sessions->onTick();

        $frames = $this->restrictionFrames();
        self::assertSame([$refusing, $fallen, $waited], self::askedPeople($frames));
        $receipts = self::sweepReceipts();
        self::assertCount(1, $receipts);
        $this->sessions->onSignalAgent(
            new AgentSignalData(self::refusal($frames[0])),
            'test',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE,
        );
        $this->sessions->onSignalAgent(
            new AgentSignalData(new AgentsGoneSignalData(
                [HilosAgentType::HILOS_USER . AgentConstants::ID_SEPARATOR . $fallen],
                AgentsGoneSignalData::REASON_WORKER_DIED,
            )),
            'test',
            HilosSignalConstants::HILOS_AGENTS_GONE,
        );
        self::assertStringContainsString(
            "Trust restriction {$restrictionId} for #{$refusing} refused: " . self::REFUSAL_DETAIL,
            $this->holderLog(),
        );
        self::assertStringContainsString(
            "Trust restriction not confirmed by #{$fallen}, its agent gone: " . AgentsGoneSignalData::REASON_WORKER_DIED,
            $this->holderLog(),
        );
        self::assertSame($receipts, self::sweepReceipts(), 'The pass still waits for one answer');

        $this->sessions->onStop();

        $closed = self::sweepReceipts();
        self::assertCount(1, $closed);
        self::assertSame($receipts[0]['id'], $closed[0]['id']);
        self::assertSame((int)$receipts[0]['open_handovers'] - 1, (int)$closed[0]['open_handovers'], 'The stop released the hold');
    }

    /**
     * The frames of a scheduled pass carry one receipt of the rule, opened by the first tick with
     * somebody to ask and kept by the next ones; a tick with nobody to ask opens none; the frames
     * of a request carry the requester's receipt.
     *
     * @throws HilosException When a fixture row cannot be written or the sweep fails
     */
    public function testScheduledFramesShareTheRuleReceiptAndRequestedOnesCarryTheRequesters(): void
    {
        $now = TimeHelper::nowMs();
        $people = [self::seedPerson(), self::seedPerson(), self::seedPerson(), self::seedPerson(), self::seedPerson()];
        foreach ($people as $userId) {
            $this->trust($userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        }
        $restrictionId = $this->record($now - self::DAY_MS, 7);
        $this->raiseJournal();
        $sweep = $this->sweep();
        $requesterReceipt = null;
        JournalReceiptScope::run(
            JournalReceiptData::agent('integration_trust_restriction_request', $this->sessions->getId()),
            static function () use ($sweep, $restrictionId, &$requesterReceipt): void {
                $requesterReceipt = JournalReceiptScope::currentId();
                $sweep->await($restrictionId, static function (): void {
                });
            },
        );
        $requested = $this->restrictionFrames();
        self::assertNotNull($requesterReceipt);
        self::assertSame(array_slice($people, 0, 2), self::askedPeople($requested));
        foreach ($requested as $frame) {
            self::assertInstanceOf(AgentSignalData::class, $frame->data);
            self::assertSame($requesterReceipt, $frame->data->receiptId);
        }

        $sweep->cron($now, self::sweepRule());

        self::assertSame([], $this->restrictionFrames());
        self::assertSame([], self::sweepReceipts(), 'A tick with nobody to ask opens no receipt');
        foreach ($requested as $frame) {
            $sweep->answered(UserSecondFactorTrustRestrictDoneSignalData::ok(self::ask($frame)));
        }

        $sweep->cron($now + self::SECOND_MS, self::sweepRule());

        $scheduled = $this->restrictionFrames();
        self::assertSame(array_slice($people, 2, 2), self::askedPeople($scheduled));
        foreach ($scheduled as $frame) {
            $sweep->answered(UserSecondFactorTrustRestrictDoneSignalData::ok(self::ask($frame)));
        }
        $sweep->cron($now + 2 * self::SECOND_MS, self::sweepRule());
        $scheduled = [...$scheduled, ...$this->restrictionFrames()];

        self::assertSame(array_slice($people, 2), self::askedPeople($scheduled));
        $receipts = self::sweepReceipts();
        self::assertCount(1, $receipts);
        self::assertSame(self::SWEEP_RULE . ' (* * * * *)', $receipts[0]['source']);
        self::assertSame($this->sessions->getId(), $receipts[0]['agent']);
        foreach ($scheduled as $frame) {
            self::assertInstanceOf(AgentSignalData::class, $frame->data);
            self::assertSame((int)$receipts[0]['id'], $frame->data->receiptId);
        }
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The framework's agent of the person, keeping its error lines
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new TrustRestrictionSweepTestUserAgent($userId);
    }

    /**
     * Starts the person's agent the way its worker does: in its own frame, under its claims.
     *
     * @param int $userId Person whose agent starts
     * @return list<string> Error lines the agent logged while starting
     */
    private function start(int $userId): array
    {
        $agent = $this->personAgent($userId);
        self::assertInstanceOf(TrustRestrictionSweepTestUserAgent::class, $agent);
        $agent->errors = [];
        $this->asPerson($userId, static fn (AbstractUserAgent $started) => $started->onStart());

        return $agent->errors;
    }

    /**
     * Sends the restriction frame the way any sender does, hands it to the person's agent and takes its one answer.
     *
     * @param int $userId Person whose trusts are restricted
     * @param int $restrictionId Restriction the frame is sent for
     * @return UserSecondFactorTrustRestrictDoneSignalData The answer, carrying the request back whole
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function apply(int $userId, int $restrictionId): UserSecondFactorTrustRestrictDoneSignalData
    {
        // What the fixtures announced on the way in is nobody's business here.
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $ask = new UserSecondFactorTrustRestrictSignalData($userId, $restrictionId, self::REPLY_SIGNAL);
        $this->settings->sendToAgent(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT, $ask);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        $answers = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === self::REPLY_SIGNAL) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                $answers[] = $queued->data->data;
            }
        }
        self::assertCount(1, $answers, 'The agent answers the frame once');
        self::assertInstanceOf(UserSecondFactorTrustRestrictDoneSignalData::class, $answers[0]);
        self::assertSame($ask->toArray(), $answers[0]->request->toArray());

        return $answers[0];
    }

    /**
     * Records a restriction in the settings library's frame, as an administrator's save does.
     *
     * @param int $savedAt Save moment (epoch milliseconds)
     * @param int $termDays Term of the restriction in days
     * @return int Id of the stored restriction
     * @throws HilosException When the truth source, the range check or the insert refuses the record
     */
    private function record(int $savedAt, int $termDays): int
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $this->settings->getId()),
            static fn (): int => Hilos::$db->secondFactorTrustRestrictions->actions->record($savedAt, $termDays),
        );
    }

    /**
     * Stores a trust of a new browser of the person, granted at a past moment and first written then.
     *
     * @param int $userId Person the browser is trusted for
     * @param int $grantedAt Moment of the grant (epoch milliseconds)
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @return int Id of the stored trust
     * @throws HilosException When an insert or the session read fails
     */
    private function trust(int $userId, int $grantedAt, int $until): int
    {
        $token = sprintf('%028d1901', ++$this->browsers);
        self::seedSession($token, $userId, self::SESSION_CREATED_AT, null);
        Database::sql(
            'INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES (?, ?, ?, ?, ?)',
            [(int)Hilos::$db->sessions->findByToken($token)?->id, $userId, $until, $grantedAt, $grantedAt],
        );

        return Database::lastInsertId();
    }

    /**
     * @return int Id of a new person row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Person', 0)");

        return Database::lastInsertId();
    }

    /**
     * @param int $userId Person whose trusts are read
     * @return array<int, array{int, int}> Last grant and end of every stored trust of the person, by id
     * @throws DatabaseException When the read fails
     */
    private static function trustRows(int $userId): array
    {
        $rows = [];
        foreach (Database::sql(
            'SELECT `id`, `granted_at`, `trusted_until` FROM `hilos_second_factor_trust` WHERE `user_id` = ? ORDER BY `id`',
            [$userId],
        )->rows() as $row) {
            $rows[(int)$row['id']] = [(int)$row['granted_at'], (int)$row['trusted_until']];
        }

        return $rows;
    }

    /**
     * @return SecondFactorTrustRestrictionSweep The holder's sweep with a portion of two
     */
    private function sweep(): SecondFactorTrustRestrictionSweep
    {
        return new SecondFactorTrustRestrictionSweep($this->sessions, self::PORTION);
    }

    /**
     * @return CronRule The rule the holder arms for the sweep
     */
    private static function sweepRule(): CronRule
    {
        return new CronRule(self::SWEEP_RULE, '* * * * *', CronRuleOrigin::FRAMEWORK);
    }

    /**
     * Takes the restriction frames queued since the last look; every other signal is dropped.
     *
     * @return list<SignalDTO> Frames to people's agents, in the order they were sent
     */
    private function restrictionFrames(): array
    {
        $frames = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT) {
                $frames[] = $queued;
            }
        }

        return $frames;
    }

    /**
     * @param SignalDTO $frame A restriction frame the sweep sent
     * @return UserSecondFactorTrustRestrictSignalData What the frame asks
     */
    private static function ask(SignalDTO $frame): UserSecondFactorTrustRestrictSignalData
    {
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        self::assertInstanceOf(UserSecondFactorTrustRestrictSignalData::class, $frame->data->data);

        return $frame->data->data;
    }

    /**
     * @param list<SignalDTO> $frames Restriction frames the sweep sent
     * @return list<int> People asked, in the order of the frames
     */
    private static function askedPeople(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): int => self::ask($frame)->userId, $frames);
    }

    /**
     * @param SignalDTO $frame A restriction frame the sweep sent
     * @return UserSecondFactorTrustRestrictDoneSignalData The person's agent's refusal of it
     */
    private static function refusal(SignalDTO $frame): UserSecondFactorTrustRestrictDoneSignalData
    {
        return new UserSecondFactorTrustRestrictDoneSignalData(
            self::ask($frame),
            'The trusts could not be changed',
            DatabaseException::class,
            self::REFUSAL_DETAIL,
        );
    }

    /**
     * Hands every frame to its person's agent, then every answer the agents gave back to the sweep.
     *
     * @param SecondFactorTrustRestrictionSweep $sweep The sweep that sent the frames
     * @param list<SignalDTO> $frames Restriction frames to carry
     * @throws HilosException When an agent or the sweep fails
     */
    private function carry(SecondFactorTrustRestrictionSweep $sweep, array $frames): void
    {
        foreach ($frames as $frame) {
            $this->deliverToPerson($frame);
        }
        $answers = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE) {
                self::assertInstanceOf(AgentSignalData::class, $queued->data);
                self::assertInstanceOf(UserSecondFactorTrustRestrictDoneSignalData::class, $queued->data->data);
                self::assertNull($queued->data->data->error);
                $answers[] = $queued->data->data;
            }
        }
        self::assertCount(count($frames), $answers, 'Every frame is answered once');
        foreach ($answers as $answer) {
            $sweep->answered($answer);
        }
    }

    /**
     * @return string Everything the holder wrote to its log stream in this case
     */
    private function holderLog(): string
    {
        $path = AgentLogStream::pathFor($this->logDirectory, $this->sessions->getId(), false);
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

        return implode("\n", $lines ?: []);
    }

    /**
     * Raises the change journal on its own connection and binds a project that keeps it - and that
     * draws a sign-in surface - so a receipt the sweep opens is a stored row.
     *
     * @throws HilosException When the journal tables or the context cannot be raised
     */
    private function raiseJournal(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            ChangeLogDatabase::CONNECTION_INDEX,
            $primary->host,
            $primary->user,
            $primary->password,
            ChangeLogDatabase::configuredName(),
            $primary->port,
            $primary->charset,
        );
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $this->journalRaised = true;
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
        }
        Database::sqlRun(str_replace(
            JournalTriggerRenderer::DATABASE_TOKEN,
            $journal,
            (string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql'),
        ));
        Schema::reset();
        Schema::initialize();
        self::bindAppClass(TrustRestrictionSweepTestHilos::class);
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
    }

    /**
     * Gives the holder the runtime a project with a sign-in surface mounts, so its whole tick runs.
     *
     * @throws HilosException When the runtime cannot be configured
     */
    private function bindSignInProject(): void
    {
        $rt = new TrustRestrictionSweepTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        $this->signInProjectBound = true;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        foreach (self::holderCollections() as $collection) {
            RtTruthSourceRegistry::registerDaemon($collection);
        }
    }

    /**
     * @return list<array<string, mixed>> Stored receipts of the sweep's rule, oldest first
     * @throws DatabaseException When the read fails
     */
    private static function sweepReceipts(): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        return Database::sql(
            "SELECT `id`, `agent`, `source`, `open_handovers` FROM {$journal}.`hilos_change_log_receipt` WHERE `action` = ? ORDER BY `id`",
            [self::SWEEP_RULE],
        )->rows();
    }

    /**
     * @return list<string> Runtime collections the holder's other sweeps write, claimed for the case
     */
    private static function holderCollections(): array
    {
        return [
            StateHilosSessionRotation::RT_COLLECTION,
            StateHilosSessionToastStack::RT_COLLECTION,
            StateHilosOAuthTrip::RT_COLLECTION,
            StateRecoveryWaiter::RT_COLLECTION,
            StateRegistrationWaiter::RT_COLLECTION,
            StateHilosCodeSendAttempt::RT_COLLECTION,
            StateHilosProfileFlow::RT_COLLECTION,
        ];
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade whose features are read
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** The framework's agent of one person, keeping the error lines it logs instead of writing them. */
final class TrustRestrictionSweepTestUserAgent extends AbstractUserAgent
{
    /** @var list<string> Error lines, oldest first */
    public array $errors = [];

    /** @param string $message Message the agent logged */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/** The framework's sessions library under a test name, its schedules open to the case. */
final class TrustRestrictionSweepTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_trust_restriction_sweep_sessions_library';

    /** @return list<CronRule> The schedules the holder keeps, as its worker reads them */
    public function cronRules(): array
    {
        return parent::cronRules();
    }
}

/** Fixture project that draws a sign-in surface and keeps the change journal. */
abstract class TrustRestrictionSweepTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CHANGE_LOG];
}

/** Runtime holding the fixture's session-stage connection roster, empty. */
final class TrustRestrictionSweepTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[TrustRestrictionSweepTestConnections::RT_COLLECTION] = TrustRestrictionSweepTestConnections::init();
    }
}

/** Session-stage connection collection of the fixture. */
final class TrustRestrictionSweepTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'trustRestrictionSweepTestConnections';
    public const string STATE_CLASS = TrustRestrictionSweepTestConnection::class;
}

/** Session-stage connection row adding no project fields. */
final class TrustRestrictionSweepTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> No project fields
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
