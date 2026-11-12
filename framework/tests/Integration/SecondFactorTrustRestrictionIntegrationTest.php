<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Shortening browser trust is a stored fact, and a trust remembers when it was last granted (HIL-1900).
 *
 * The settings library is the one writer of a restriction: it adds a row when an administrator
 * shortens the trust term or switches the second factor off with the trusted browsers forgotten, and
 * nobody changes the row afterwards. Any other process asking to add one is refused by the truth
 * source. A trust keeps the moment it was last granted beside the moment it was first written, so a
 * restriction can tell the trusts granted before it was saved from the ones granted after.
 *
 * The person's agent applies the stored restrictions to the person's trusts on a service frame, in
 * one transaction, and answers under the reply name the frame carries: a trust granted no later
 * than a restriction was saved is cut to its limit, or removed by a restriction of 0 days, and a
 * trust granted after it is left alone. The sign-in door counts a restriction before the agent has
 * applied it.
 *
 * Every writer runs in its own frame and under the claims its own class declares: the settings library
 * for the restrictions, the sessions library - the holder that grants a browser's trust - for the
 * trusts, and the person's agent for applying the restrictions. The frames between them are carried
 * by the case. Moments are named relative to now and nothing waits for the clock.
 */
final class SecondFactorTrustRestrictionIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const string SESSION_TOKEN = 'ab000000000000000000000000001900';

    private const string OTHER_SESSION_TOKEN = 'cd000000000000000000000000001900';

    private const string THIRD_SESSION_TOKEN = 'ef000000000000000000000000001900';

    /** Reply name a sender of the restriction frame registers for itself. */
    private const string REPLY_SIGNAL = 'integration_trust_restrict_done';

    private const int DAY_MS = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

    private const int HOUR_MS = TimeConstants::SECONDS_PER_HOUR * TimeConstants::MS_PER_SECOND;

    private const int SESSION_CREATED_AT = 1791626400000;

    /** Days a trust is written for. */
    private const int TRUST_DAYS = 30;

    private SettingsLibraryAgent $settings;

    private TrustRestrictionTestSessionsLibrary $sessions;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->settings = new SettingsLibraryAgent();
        $this->sessions = new TrustRestrictionTestSessionsLibrary();
        OwnershipDeclaration::claimDb($this->settings::class, $this->settings->getId());
        OwnershipDeclaration::claimDb($this->sessions::class, $this->sessions->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->settings->getId(), $this->sessions->getId()] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When the record or the read back fails
     */
    public function testTheSettingsLibraryRecordsARestrictionAndGetsItsId(): void
    {
        $savedAt = TimeHelper::nowMs();

        $id = $this->recordAs($this->settings->getId(), $savedAt, 7);

        self::assertSame([[(string)$id, (string)$savedAt, '7']], self::restrictionRows());
        $restriction = Hilos::$db->secondFactorTrustRestrictions[$id];
        self::assertSame($savedAt, $restriction->savedAt);
        self::assertSame(7, $restriction->termDays);
    }

    /**
     * Both ends of the range are a term; one day past either is not, and nothing is written for it.
     *
     * @throws HilosException When a record or the read back fails
     */
    public function testATermOutsideZeroToTheLongestTrustIsRefusedAndNothingIsWritten(): void
    {
        $savedAt = TimeHelper::nowMs();
        $revoking = $this->recordAs($this->settings->getId(), $savedAt, 0);
        $longest = $this->recordAs($this->settings->getId(), $savedAt, SecondFactorSettings::TRUST_DAYS_MAX);

        foreach ([-1, SecondFactorSettings::TRUST_DAYS_MAX + 1] as $termDays) {
            try {
                $this->recordAs($this->settings->getId(), $savedAt, $termDays);
                self::fail($termDays . ' days must be refused as a trust restriction term');
            } catch (InvalidArgumentException $refusal) {
                self::assertStringContainsString((string)$termDays, $refusal->getMessage());
            }
        }

        self::assertSame([
            [(string)$revoking, (string)$savedAt, '0'],
            [(string)$longest, (string)$savedAt, (string)SecondFactorSettings::TRUST_DAYS_MAX],
        ], self::restrictionRows());
    }

    /**
     * The holder that grants a browser's trust may not add a restriction past the settings library.
     *
     * @throws HilosException When the read back fails
     */
    public function testAnotherWriterOfARestrictionIsRefused(): void
    {
        try {
            $this->recordAs($this->sessions->getId(), TimeHelper::nowMs(), 7);
            self::fail('The sessions library must not add a trust restriction past the settings library');
        } catch (CreateNotAllowedException $refusal) {
            self::assertStringContainsString($this->sessions->getId(), $refusal->getMessage());
        }

        self::assertSame([], self::restrictionRows());
    }

    /**
     * A new pair is written and granted at the same moment.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testANewTrustIsGrantedWhenItIsWritten(): void
    {
        [$userId, $sessionId] = $this->seedSignedInPerson();
        $until = TimeHelper::nowMs() + self::TRUST_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

        $before = TimeHelper::nowMs();
        $this->trustAs($sessionId, $userId, $until);
        $after = TimeHelper::nowMs();

        [$id, $createdAt, $grantedAt, $trustedUntil] = self::trustRow($sessionId, $userId);
        self::assertGreaterThanOrEqual($before, $grantedAt);
        self::assertLessThanOrEqual($after, $grantedAt);
        self::assertSame($grantedAt, $createdAt);
        self::assertSame($until, $trustedUntil);
        self::assertSame($grantedAt, Hilos::$db->secondFactorTrusts[$id]->grantedAt);
    }

    /**
     * Extending a pair grants it again and moves its end; the moment it was first written stays.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testExtendingATrustGrantsItAgainAndKeepsWhenItWasWritten(): void
    {
        [$userId, $sessionId] = $this->seedSignedInPerson();
        $dayMs = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $writtenAt = TimeHelper::nowMs() - 10 * $dayMs;
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES (?, ?, ?, ?, ?)',
            [$sessionId, $userId, $writtenAt + self::TRUST_DAYS * $dayMs, $writtenAt, $writtenAt],
        );
        $until = TimeHelper::nowMs() + self::TRUST_DAYS * $dayMs;

        $before = TimeHelper::nowMs();
        $this->trustAs($sessionId, $userId, $until);
        $after = TimeHelper::nowMs();

        [$id, $createdAt, $grantedAt, $trustedUntil] = self::trustRow($sessionId, $userId);
        self::assertSame($writtenAt, $createdAt);
        self::assertGreaterThanOrEqual($before, $grantedAt);
        self::assertLessThanOrEqual($after, $grantedAt);
        self::assertSame($until, $trustedUntil);
        self::assertSame($grantedAt, Hilos::$db->secondFactorTrusts[$id]->grantedAt);
        self::assertSame($writtenAt, Hilos::$db->secondFactorTrusts[$id]->createdAt);
    }

    /**
     * A trust granted before the save is cut to the save plus the term; one granted after it is not.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testACutShortensOnlyTheTrustsGrantedBeforeTheSave(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $beforeSession] = $this->seedSignedInPerson();
        $afterSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $userId);
        $before = self::insertTrust($beforeSession, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $after = self::insertTrust($afterSession, $userId, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $savedAt = $now - self::DAY_MS;
        $restrictionId = $this->recordAs($this->settings->getId(), $savedAt, 7);

        $answer = $this->apply($userId, $restrictionId);

        self::assertNull($answer->error);
        self::assertSame([
            $before => [$now - 2 * self::DAY_MS, $now - 2 * self::DAY_MS, $savedAt + 7 * self::DAY_MS],
            $after => [$now - 12 * self::HOUR_MS, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS],
        ], self::trustRows($userId));
        self::assertFalse($this->personAgent($userId)->shouldStop());
    }

    /**
     * A term of 0 takes out the trusts granted before the save, and the door lets none of them in again.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testATermOfZeroRemovesOnlyTheTrustsGrantedBeforeTheSave(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $beforeSession] = $this->seedSignedInPerson();
        $afterSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $userId);
        self::insertTrust($beforeSession, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $after = self::insertTrust($afterSession, $userId, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        $restrictionId = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 0);

        $answer = $this->apply($userId, $restrictionId);

        self::assertNull($answer->error);
        self::assertSame([
            $after => [$now - 12 * self::HOUR_MS, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS],
        ], self::trustRows($userId));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($beforeSession, $userId, self::TRUST_DAYS));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($afterSession, $userId, self::TRUST_DAYS));
    }

    /**
     * The same frame again, and a frame for an earlier restriction after a later one, change nothing.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testApplyingAgainOrForAnEarlierRestrictionChangesNothing(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $sessionId] = $this->seedSignedInPerson();
        $trust = self::insertTrust($sessionId, $userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $earlier = $this->recordAs($this->settings->getId(), $now - 2 * self::DAY_MS, 14);
        $later = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 7);

        self::assertNull($this->apply($userId, $later)->error);
        $applied = self::trustRows($userId);
        self::assertSame([$trust => [$now - 3 * self::DAY_MS, $now - 3 * self::DAY_MS, $now + 6 * self::DAY_MS]], $applied);

        self::assertNull($this->apply($userId, $later)->error);
        self::assertSame($applied, self::trustRows($userId));
        self::assertNull($this->apply($userId, $earlier)->error);
        self::assertSame($applied, self::trustRows($userId));
    }

    /**
     * 30 to 7, back to 30 (nothing recorded), then 30 to 14, applied once: each trust gets the limit it falls under.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testSeveralChangesAppliedAtOnceGiveEachTrustItsOwnLimit(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $firstSession] = $this->seedSignedInPerson();
        $secondSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $userId);
        $thirdSession = $this->seedBrowser(self::THIRD_SESSION_TOKEN, $userId);
        $beforeBoth = self::insertTrust($firstSession, $userId, $now - 4 * self::DAY_MS, $now + 26 * self::DAY_MS);
        $toSeven = $this->recordAs($this->settings->getId(), $now - 3 * self::DAY_MS, 7);
        $between = self::insertTrust($secondSession, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $toFourteen = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 14);
        $afterBoth = self::insertTrust($thirdSession, $userId, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS);
        self::assertGreaterThan($toSeven, $toFourteen);

        self::assertNull($this->apply($userId, $toFourteen)->error);

        self::assertSame([
            $beforeBoth => [$now - 4 * self::DAY_MS, $now - 4 * self::DAY_MS, $now + 4 * self::DAY_MS],
            $between => [$now - 2 * self::DAY_MS, $now - 2 * self::DAY_MS, $now + 13 * self::DAY_MS],
            $afterBoth => [$now - 12 * self::HOUR_MS, $now - 12 * self::HOUR_MS, $now + 29 * self::DAY_MS],
        ], self::trustRows($userId));
    }

    /**
     * Extending a pair after the save grants it again, and the older restriction no longer cuts it.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testATrustGrantedAgainAfterTheSaveIsNotCut(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $sessionId] = $this->seedSignedInPerson();
        $trust = self::insertTrust($sessionId, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $restrictionId = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 7);
        $until = TimeHelper::nowMs() + self::TRUST_DAYS * self::DAY_MS;
        $this->trustAs($sessionId, $userId, $until);
        $granted = self::trustRows($userId);
        self::assertGreaterThan($now - self::DAY_MS, $granted[$trust][1]);

        self::assertNull($this->apply($userId, $restrictionId)->error);

        self::assertSame($granted, self::trustRows($userId));
        self::assertSame($until, $granted[$trust][2]);
    }

    /**
     * Before the person's agent applies anything, the door refuses a trust past the limit of a restriction
     * saved since its grant and one taken out by a term of 0, and lets in one granted after both.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheDoorCountsARestrictionBeforeItIsApplied(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $pastLimitSession] = $this->seedSignedInPerson();
        $removedSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $userId);
        $grantedAfterSession = $this->seedBrowser(self::THIRD_SESSION_TOKEN, $userId);
        self::insertTrust($pastLimitSession, $userId, $now - 10 * self::DAY_MS, $now + 20 * self::DAY_MS);
        $this->recordAs($this->settings->getId(), $now - 9 * self::DAY_MS, 7);
        self::insertTrust($removedSession, $userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        $this->recordAs($this->settings->getId(), $now - 2 * self::DAY_MS, 0);
        self::insertTrust($grantedAfterSession, $userId, $now - self::HOUR_MS, $now + 29 * self::DAY_MS);
        $untouched = self::trustRows($userId);

        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($pastLimitSession, $userId, self::TRUST_DAYS));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($removedSession, $userId, self::TRUST_DAYS));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($grantedAfterSession, $userId, self::TRUST_DAYS));
        self::assertSame($untouched, self::trustRows($userId));
    }

    /**
     * A trust under a restriction whose limit is still ahead is let in until that limit.
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheDoorLetsInATrustWhoseRestrictionLimitIsAhead(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $sessionId] = $this->seedSignedInPerson();
        self::insertTrust($sessionId, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 7);

        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, self::TRUST_DAYS));
    }

    /**
     * A row that fails takes back the row written before it in the same application, and the answer is a refusal.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testAFailedRowTakesEveryRowOfTheApplicationBack(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $cutSession] = $this->seedSignedInPerson();
        $removedSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $userId);
        $cut = self::insertTrust($cutSession, $userId, $now - self::DAY_MS, $now + 29 * self::DAY_MS);
        $removed = self::insertTrust($removedSession, $userId, $now - 3 * self::DAY_MS, $now + 27 * self::DAY_MS);
        self::assertGreaterThan($cut, $removed, 'The cut row is written before the removed one');
        $this->recordAs($this->settings->getId(), $now - 2 * self::DAY_MS, 0);
        $restrictionId = $this->recordAs($this->settings->getId(), $now - 12 * self::HOUR_MS, 7);
        $before = self::trustRows($userId);
        Database::sqlRun("CREATE TRIGGER refuse_test_trust_delete BEFORE DELETE ON hilos_second_factor_trust
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trust storage failure'");
        try {
            $answer = $this->apply($userId, $restrictionId);
        } finally {
            Database::sqlRun('DROP TRIGGER refuse_test_trust_delete');
        }

        self::assertNotNull($answer->error);
        self::assertNotNull($answer->errorDetail);
        self::assertSame($before, self::trustRows($userId));
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($now + 29 * self::DAY_MS, Hilos::$db->secondFactorTrusts[$cut]->trustedUntil);
        self::assertTrue(isset(Hilos::$db->secondFactorTrusts[$removed]));
    }

    /**
     * A frame for a restriction that is not stored is refused in its own words, and nothing is written.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testAnUnknownRestrictionIsRefusedAndNothingIsWritten(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $sessionId] = $this->seedSignedInPerson();
        self::insertTrust($sessionId, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $stored = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 7);
        $before = self::trustRows($userId);

        $answer = $this->apply($userId, $stored + 1);

        self::assertSame('Trust restriction ' . ($stored + 1) . ' is not saved', $answer->error);
        self::assertNull($answer->errorType);
        self::assertSame($before, self::trustRows($userId));
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanApplied(): void
    {
        $now = TimeHelper::nowMs();
        [$userId] = $this->seedSignedInPerson();
        $otherId = self::seedPerson();
        $otherSession = $this->seedBrowser(self::OTHER_SESSION_TOKEN, $otherId);
        self::insertTrust($otherSession, $otherId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $restrictionId = $this->recordAs($this->settings->getId(), $now - self::DAY_MS, 0);
        $before = self::trustRows($otherId);

        $this->expectException(AgentException::class);
        try {
            $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->onSignalAgent(
                new AgentSignalData(data: new UserSecondFactorTrustRestrictSignalData($otherId, $restrictionId, self::REPLY_SIGNAL)),
                '',
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
            ));
        } finally {
            self::assertSame($before, self::trustRows($otherId));
        }
    }

    /**
     * An account folded into another one still applies the restriction to its trusts, answers and stops.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testAFoldedAccountAppliesTheRestrictionAndStops(): void
    {
        $now = TimeHelper::nowMs();
        [$userId, $sessionId] = $this->seedSignedInPerson();
        $survivorId = self::seedPerson();
        $trust = self::insertTrust($sessionId, $userId, $now - 2 * self::DAY_MS, $now + 28 * self::DAY_MS);
        $savedAt = $now - self::DAY_MS;
        $restrictionId = $this->recordAs($this->settings->getId(), $savedAt, 7);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            static fn () => Hilos::$db->userMerges->actions->add($userId, $survivorId),
        );

        self::assertNull($this->apply($userId, $restrictionId)->error);

        self::assertSame([$trust => [$now - 2 * self::DAY_MS, $now - 2 * self::DAY_MS, $savedAt + 7 * self::DAY_MS]], self::trustRows($userId));
        self::assertTrue($this->personAgent($userId)->shouldStop());
    }

    /**
     * A person with no trusts - erased, or never trusted a browser - has nothing to break, and is answered ok.
     *
     * @throws HilosException When a fixture row cannot be written or the frame fails
     */
    public function testAPersonWithoutTrustsIsAnsweredOk(): void
    {
        [$userId] = $this->seedSignedInPerson();
        $restrictionId = $this->recordAs($this->settings->getId(), TimeHelper::nowMs() - self::DAY_MS, 0);

        self::assertNull($this->apply($userId, $restrictionId)->error);
        self::assertSame([], self::trustRows($userId));
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
     * @param string $agentId Agent whose frame the record runs in
     * @param int $savedAt Save moment (epoch milliseconds)
     * @param int $termDays Term of the restriction in days
     * @return int Id of the stored restriction
     * @throws HilosException When the truth source, the range check or the insert refuses the record
     */
    private function recordAs(string $agentId, int $savedAt, int $termDays): int
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $agentId),
            static fn (): int => Hilos::$db->secondFactorTrustRestrictions->actions->record($savedAt, $termDays),
        );
    }

    /**
     * Trusts a browser for a person the way a sign-in let through does: in the sessions library's frame.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @throws HilosException When the trust cannot be written
     */
    private function trustAs(int $sessionId, int $userId, int $until): void
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->sessions->getId()),
            static fn () => Hilos::$db->secondFactorTrusts->actions->trust($sessionId, $userId, $until),
        );
    }

    /**
     * @return array{int, int} Id of a new person, and the session row of the browser they are signed in on
     * @throws HilosException When an insert or the session read fails
     */
    private function seedSignedInPerson(): array
    {
        $userId = self::seedPerson();

        return [$userId, $this->seedBrowser(self::SESSION_TOKEN, $userId)];
    }

    /**
     * @param string $token Session token of the browser
     * @param int $userId Person signed in on it
     * @return int Session row of the browser
     * @throws HilosException When the insert or the session read fails
     */
    private function seedBrowser(string $token, int $userId): int
    {
        self::seedSession($token, $userId, self::SESSION_CREATED_AT, null);

        return (int)Hilos::$db->sessions->findByToken($token)?->id;
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
     * Stores a trust granted at a past moment, first written at that same moment.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param int $grantedAt Moment of the grant (epoch milliseconds)
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @return int Id of the stored trust
     * @throws DatabaseException When the insert fails
     */
    private static function insertTrust(int $sessionId, int $userId, int $grantedAt, int $until): int
    {
        Database::sql(
            'INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES (?, ?, ?, ?, ?)',
            [$sessionId, $userId, $until, $grantedAt, $grantedAt],
        );

        return Database::lastInsertId();
    }

    /**
     * @return list<list<string>> Every stored restriction as id, save moment and term, by id
     * @throws DatabaseException When the read fails
     */
    private static function restrictionRows(): array
    {
        return array_map(
            static fn (array $row): array => array_map('strval', array_values($row)),
            Database::sql('SELECT `id`, `saved_at`, `term_days` FROM `hilos_second_factor_trust_restriction` ORDER BY `id`')->rows(),
        );
    }

    /**
     * @param int $userId Person whose trusts are read
     * @return array<int, array{int, int, int}> First written, last granted and end of every stored trust of the person, by id
     * @throws DatabaseException When the read fails
     */
    private static function trustRows(int $userId): array
    {
        $rows = [];
        foreach (Database::sql(
            'SELECT `id`, `created_at`, `granted_at`, `trusted_until` FROM `hilos_second_factor_trust` WHERE `user_id` = ? ORDER BY `id`',
            [$userId],
        )->rows() as $row) {
            $rows[(int)$row['id']] = [(int)$row['created_at'], (int)$row['granted_at'], (int)$row['trusted_until']];
        }

        return $rows;
    }

    /**
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @return array{int, int, int, int} Id, first written, last granted and end of the pair's stored trust
     * @throws DatabaseException When the read fails
     */
    private static function trustRow(int $sessionId, int $userId): array
    {
        $row = Database::sql(
            'SELECT `id`, `created_at`, `granted_at`, `trusted_until` FROM `hilos_second_factor_trust`'
                . ' WHERE `session_id` = ? AND `user_id` = ?',
            [$sessionId, $userId],
        )->row();

        return [(int)$row['id'], (int)$row['created_at'], (int)$row['granted_at'], (int)$row['trusted_until']];
    }
}

/** The framework's sessions library under a test name, with nothing overridden. */
final class TrustRestrictionTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'integration_trust_restriction_sessions_library';
}
