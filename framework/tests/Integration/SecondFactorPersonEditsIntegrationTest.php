<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\DTO\UserSecondFactorEnrollDropDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollDropSignalData;
use Hilos\Users\DTO\UserSecondFactorProveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorProveSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorRemoveSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetCancelSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * A person's second factor is edited by that person's agent, and only brought into being by the
 * users library (HIL-1406).
 *
 * The library keeps starting an enrolment, issuing a set of backup codes and opening a removal,
 * each with what it replaces, but a library that takes a code's step, burns a code, cancels a
 * removal or writes the person's settings itself is refused by the truth source. The same edits
 * handed to the person's agent are written: the code check that is its own write - a step taken
 * once, a backup code burned once, the wrong codes counted until they lock - the last app taking
 * the factor with it, a due removal carried out once, a reminder marked once. The agent answers
 * every frame, and refuses one meant for someone else.
 *
 * Every writer runs in its own frame and under the claims its own class declares; the frames
 * between them are carried by the case.
 */
final class SecondFactorPersonEditsIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Accept key standing in for the person's browser. */
    private const string ACCEPT_KEY = 'accept-second-factor-edits';

    /** The RFC 6238 test secret, as the first app enrolled here holds it. */
    private const string SECRET_BYTES = '12345678901234567890';

    /** The secret of a second app. */
    private const string OTHER_SECRET_BYTES = '09876543210987654321';

    /** Wrong app codes that lock, as the environment sets them by default. */
    private const int CEILING = 10;

    /** A moment a removal is still waiting at (epoch milliseconds of 2036-01-01 00:00:00 UTC). */
    private const int FUTURE = 2082758400000;

    private SecondFactorEditsTestUsersLibrary $users;

    private ?SignalRouter $previousRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->users = new SecondFactorEditsTestUsersLibrary();
        OwnershipDeclaration::claimDb($this->users::class, $this->users->getId());
    }

    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        TruthSourceRegistry::unregisterAgent($this->users->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->users->getId()));
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryEditingASecondFactorItselfIsRefused(): void
    {
        $userId = self::seedPerson();
        $factor = self::seedApp($userId, self::SECRET_BYTES);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['abcdefghjk']);
        $code = Hilos::$db->secondFactorBackupCodes->findUnused($userId, 'abcdefghjk');
        $reset = Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");
        $resetRow = Hilos::$db->secondFactorResets[(int)$reset->id];

        $edits = [
            'a step taken' => static fn () => $factor->actions->acceptStep(Totp::stepAt(time())),
            'an app confirmed' => static fn () => $factor->actions->confirm('Renamed'),
            'a backup code burned' => static fn () => $code?->actions->spend(),
            'a removal canceled' => static fn () => $resetRow->actions->cancel(),
            'a removal carried out' => static fn () => $resetRow->actions->complete(),
            'a reminder marked' => static fn () => $resetRow->actions->remind(self::FUTURE),
            'the wait chosen' => static fn () => Hilos::$db->secondFactorSettings->actions->setResetWait($userId, 20, null, null),
            'a wrong code counted' => static fn () => Hilos::$db->secondFactorSettings->actions->countAppCodeMiss($userId, 86400),
            'the settings removed' => static fn () => Hilos::$db->secondFactorSettings->actions->deleteForUser($userId),
            'an unfinished enrolment dropped' => static fn () => Hilos::$db->secondFactors->actions->dropUnfinished($userId),
        ];
        foreach ($edits as $edit => $write) {
            try {
                $this->inLibrary($write);
                self::fail("The users library must not write {$edit} past the person's agent");
            } catch (WriteNotAllowedException | CreateNotAllowedException $refusal) {
                self::assertStringContainsString($this->users->getId(), $refusal->getMessage(), $edit);
            }
        }

        self::assertArrayNotHasKey($userId, Hilos::$db->secondFactorSettings);
        self::assertNotNull(Hilos::$db->secondFactorBackupCodes->findUnused($userId, 'abcdefghjk'));
        self::assertNotNull(Hilos::$db->secondFactorResets->liveOf($userId));
    }

    /**
     * An enrolment the library starts is only created: the unfinished one before it is the person's
     * agent's to drop, so two starts stand side by side (HIL-1903). A set of backup codes still takes
     * the old set with it (HIL-1904).
     *
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testTheUsersLibraryCreatesEnrolmentsAndReplacesTheCodeSet(): void
    {
        $userId = self::seedPerson();
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['oldoldoldo']);

        $this->inLibrary(static function () use ($userId): void {
            Hilos::$db->secondFactors->actions->startEnrolment($userId, 'Phone', Base32::encode(self::SECRET_BYTES));
            Hilos::$db->secondFactors->actions->startEnrolment($userId, 'Tablet', Base32::encode(self::OTHER_SECRET_BYTES));
            Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['newnewnewn']);
            Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");
        });

        $started = Hilos::$db->secondFactors->listByUser($userId);
        self::assertSame(['Phone', 'Tablet'], array_map(static fn (SecondFactor $factor): string => $factor->label, $started),
            'The later start took nothing out');
        self::assertSame([null, null], array_map(static fn (SecondFactor $factor): ?int => $factor->confirmedAt, $started));
        self::assertSame(Base32::encode(self::SECRET_BYTES), $started[0]->readSecret());
        self::assertSame(Base32::encode(self::OTHER_SECRET_BYTES), $started[1]->readSecret());
        self::assertNull(Hilos::$db->secondFactorBackupCodes->findUnused($userId, 'oldoldoldo'), 'The old set died with the new one');
        self::assertNotNull(Hilos::$db->secondFactorBackupCodes->findUnused($userId, 'newnewnewn'));
        self::assertSame("cancel-{$userId}", Hilos::$db->secondFactorResets->liveOf($userId)?->readCancelToken());
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testAnAppCodeTakesItsStepOnceAndABackupCodeBurnsOnce(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['abcdefghjk']);
        $code = Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time()));

        $first = $this->prove($userId, $code, backupCode: false);
        self::assertNull($first->error);
        self::assertFalse($first->missed);

        $replayed = $this->prove($userId, $code, backupCode: false);
        self::assertSame(SecondFactorMessages::INVALID_CODE, $replayed->error);
        self::assertTrue($replayed->missed);
        self::assertSame(1, Hilos::$db->secondFactorSettings[$userId]->appCodeMisses, 'The replay was counted');

        $burned = $this->prove($userId, BackupCodeGenerator::display('abcdefghjk'), backupCode: true);
        self::assertNull($burned->error);
        $again = $this->prove($userId, BackupCodeGenerator::display('abcdefghjk'), backupCode: true);
        self::assertSame(SecondFactorMessages::INVALID_CODE, $again->error);
        self::assertTrue($again->missed);
        self::assertSame(1, Hilos::$db->secondFactorSettings[$userId]->appCodeMisses, 'A backup code is not counted');
    }

    /**
     * The person's settings row is born of their first wrong code, and the miss at the ceiling says
     * it put the lock; a right code under the lock is refused unchecked and uncounted.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testWrongAppCodesAreCountedUntilTheyLock(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        self::assertArrayNotHasKey($userId, Hilos::$db->secondFactorSettings);

        $missed = $this->prove($userId, self::wrongCode(), backupCode: false);
        self::assertTrue($missed->missed);
        self::assertNull($missed->lockMisses);
        self::assertSame(1, Hilos::$db->secondFactorSettings[$userId]->appCodeMisses, 'The agent created the row on the first miss');

        for ($miss = 2; $miss < self::CEILING; $miss++) {
            self::assertSame(SecondFactorMessages::INVALID_CODE, $this->prove($userId, self::wrongCode(), backupCode: false)->error);
        }
        $locking = $this->prove($userId, self::wrongCode(), backupCode: false);
        self::assertTrue($locking->missed);
        self::assertSame(self::CEILING, $locking->lockMisses);
        self::assertNotNull($locking->lockUntil);
        self::assertStringStartsWith('Too many wrong codes', (string)$locking->error);

        $underLock = $this->prove($userId, Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time())), backupCode: false);
        self::assertSame($locking->error, $underLock->error);
        self::assertFalse($underLock->missed, 'A code under the lock is not checked');
        self::assertNull($underLock->lockMisses);
        $setting = Hilos::$db->secondFactorSettings[$userId];
        self::assertSame(0, $setting->appCodeMisses);
        self::assertSame(1, $setting->appCodeLockStep);
    }

    /**
     * Two frames taking off the last two apps one after the other: the second switches the factor off
     * whole, with the backup codes and the removal that stood.
     *
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testTheLastAppTakesTheFactorWithItInOneTurn(): void
    {
        $userId = self::seedPerson();
        $phone = self::seedApp($userId, self::SECRET_BYTES);
        $tablet = self::seedApp($userId, self::OTHER_SECRET_BYTES);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc']);
        $reset = Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");

        $first = $this->remove($userId, (int)$phone->id, 'aaaaaaaaaa');
        self::assertNull($first->error);
        self::assertFalse($first->switchedOff);
        self::assertCount(1, Hilos::$db->secondFactors->confirmedOf($userId));
        self::assertNotNull(Hilos::$db->secondFactorResets->liveOf($userId), 'Taking off one app of two leaves the removal');

        $last = $this->remove($userId, (int)$tablet->id, 'bbbbbbbbbb');
        self::assertNull($last->error);
        self::assertTrue($last->switchedOff);
        self::assertSame([], Hilos::$db->secondFactors->confirmedOf($userId));
        self::assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser($userId));
        self::assertNull(Hilos::$db->secondFactorResets->liveOf($userId));
        self::assertNotNull(self::endedAt((int)$reset->id, 'canceled_at'));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testADueRemovalIsCarriedOutOnceAndACancelThatWonLeavesTheFactor(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['abcdefghjk']);
        $reset = Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");

        $done = $this->carryOut($userId, (int)$reset->id);
        self::assertNull($done->error);
        self::assertTrue($done->carriedOut);
        self::assertSame([], Hilos::$db->secondFactors->confirmedOf($userId));
        self::assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser($userId));
        self::assertNotNull(self::endedAt((int)$reset->id, 'completed_at'));

        self::assertFalse($this->carryOut($userId, (int)$reset->id)->carriedOut, 'The frame sent again does nothing');

        $kept = self::seedPerson();
        self::seedApp($kept, self::SECRET_BYTES);
        $canceled = Hilos::$db->secondFactorResets->actions->request($kept, self::FUTURE, "cancel-{$kept}");
        Hilos::$db->secondFactorResets[(int)$canceled->id]->actions->cancel();

        $lost = $this->carryOut($kept, (int)$canceled->id);
        self::assertNull($lost->error);
        self::assertFalse($lost->carriedOut);
        self::assertCount(1, Hilos::$db->secondFactors->confirmedOf($kept), 'The cancel that won leaves the factor');
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testAReminderIsMarkedOnce(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        $reset = Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `notified_at` = ? WHERE `id` = ?',
            [
                TimeHelper::nowMs() - 2 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
                (int)$reset->id,
            ],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $staleBefore = TimeHelper::nowMs() - TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

        $marked = $this->remind($userId, (int)$reset->id, $staleBefore);
        self::assertNull($marked->error);
        self::assertTrue($marked->marked);

        self::assertFalse($this->remind($userId, (int)$reset->id, $staleBefore)->marked, 'The frame sent again marks nothing');
    }

    /**
     * A reminder is due on the inclusive bounds of its deadline and its last announcement, and the
     * frame that marks it does so once.
     */
    public function testReminderBoundaryKeepsFixedMillisecondsAndMarksOnce(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        $resetId = (int)Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}")->id;
        $now = 1767312000123;
        $notifiedBefore = 1767225600456;

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ?, `notified_at` = ? WHERE `id` = ?',
            [$now + 1, $notifiedBefore, $resetId],
        );
        $this->dropLoadedResets();
        self::assertSame([$resetId], $this->reminderIds($now, $notifiedBefore));

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ? WHERE `id` = ?',
            [$now, $resetId],
        );
        $this->dropLoadedResets();
        self::assertSame([], $this->reminderIds($now, $notifiedBefore), 'A deadline at now is not waiting');

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ?, `notified_at` = ? WHERE `id` = ?',
            [$now + 1, $notifiedBefore + 1, $resetId],
        );
        $this->dropLoadedResets();
        self::assertSame([], $this->reminderIds($now, $notifiedBefore), 'An announcement after the bound is fresh');

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `notified_at` = ? WHERE `id` = ?',
            [$notifiedBefore, $resetId],
        );
        $this->dropLoadedResets();
        self::assertSame([$resetId], $this->reminderIds($now, $notifiedBefore));

        $marked = $this->remind($userId, $resetId, $notifiedBefore);
        self::assertNull($marked->error);
        self::assertTrue($marked->marked);
        self::assertSame([], $this->reminderIds($now, $notifiedBefore));
        $notifiedAt = (int)Database::sql(
            'SELECT `notified_at` FROM `hilos_second_factor_reset` WHERE `id` = ?',
            [$resetId],
        )->field('notified_at');
        self::assertGreaterThan($notifiedBefore, $notifiedAt);

        $repeated = $this->remind($userId, $resetId, $notifiedBefore);
        self::assertFalse($repeated->marked);
        self::assertSame($notifiedAt, (int)Database::sql(
            'SELECT `notified_at` FROM `hilos_second_factor_reset` WHERE `id` = ?',
            [$resetId],
        )->field('notified_at'));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testMissingRemovalsAreAnsweredWithoutChangingTheFactor(): void
    {
        $userId = self::seedPerson();
        self::seedApp($userId, self::SECRET_BYTES);
        self::assertArrayNotHasKey(9999, Hilos::$db->secondFactorResets);

        foreach ([9999, null] as $resetId) {
            $ask = new UserSecondFactorResetCancelSignalData(
                userId: $userId,
                resetId: $resetId,
                replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE,
                acceptKey: self::ACCEPT_KEY,
                requestId: 'missing-reset',
                action: HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL,
                successMessage: null,
            );
            $canceled = $this->carry(
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL,
                $ask,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE,
            );
            self::assertInstanceOf(UserSecondFactorResetCancelDoneSignalData::class, $canceled);
            self::assertSame($ask, $canceled->ask);
            self::assertFalse($canceled->canceled);
            self::assertNull($canceled->error);
        }

        $due = $this->carryOut($userId, 9999);
        self::assertFalse($due->carriedOut);
        self::assertNull($due->error);

        $reminded = $this->remind($userId, 9999, self::FUTURE);
        self::assertFalse($reminded->marked);
        self::assertNull($reminded->error);
        self::assertCount(1, Hilos::$db->secondFactors->confirmedOf($userId));
        self::assertArrayNotHasKey($userId, Hilos::$db->secondFactorSettings);
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAFrameForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        self::seedApp($otherId, self::SECRET_BYTES);

        $this->expectException(AgentException::class);

        $this->asPerson($userId, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(data: self::proveAsk($otherId, '000000', false)),
            '',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE,
        ));
    }

    /**
     * @throws HilosException When a fixture row cannot be written or a frame fails
     */
    public function testAnEnrolmentDropRemovesTheUnfinishedOnesAndLeavesTheConfirmedOne(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        $confirmed = self::seedApp($userId, self::SECRET_BYTES);
        self::seedUnfinished($userId, 'Phone');
        self::seedUnfinished($userId, 'Tablet');
        self::seedUnfinished($otherId, 'Elsewhere');
        self::assertCount(3, Hilos::$db->secondFactors->listByUser($userId));

        $dropped = $this->dropEnrolment($userId);

        self::assertNull($dropped->error);
        $left = Hilos::$db->secondFactors->listByUser($userId);
        self::assertCount(1, $left, 'Both unfinished enrolments went');
        self::assertSame($confirmed->id, $left[0]->id, 'The confirmed app stayed');
        self::assertNotNull($left[0]->confirmedAt);
        self::assertCount(1, Hilos::$db->secondFactors->listByUser($otherId), 'Another person\'s enrolment is not this agent\'s to drop');

        $again = $this->dropEnrolment($userId);

        self::assertNull($again->error, 'Nothing left to drop is an answer like any other');
        self::assertCount(1, Hilos::$db->secondFactors->listByUser($userId));
    }

    /**
     * @throws HilosException When a fixture row cannot be written
     */
    public function testAnEnrolmentDropForAnotherPersonIsRefusedRatherThanWritten(): void
    {
        $userId = self::seedPerson();
        $otherId = self::seedPerson();
        self::seedUnfinished($otherId, 'Elsewhere');

        try {
            $this->asPerson($userId, fn ($agent) => $agent->onSignalAgent(
                new AgentSignalData(data: self::dropAsk($otherId)),
                '',
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP,
            ));
            self::fail('A frame naming another person was written');
        } catch (AgentException) {
            self::assertCount(1, Hilos::$db->secondFactors->listByUser($otherId));
        }
    }

    /**
     * @param int $userId Person whose code it is
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @return UserSecondFactorProveDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function prove(int $userId, string $code, bool $backupCode): UserSecondFactorProveDoneSignalData
    {
        $answer = $this->carry(
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE,
            self::proveAsk($userId, $code, $backupCode),
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
        );
        self::assertInstanceOf(UserSecondFactorProveDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * @param int $userId Person whose unfinished enrolments to drop
     * @return UserSecondFactorEnrollDropDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function dropEnrolment(int $userId): UserSecondFactorEnrollDropDoneSignalData
    {
        $answer = $this->carry(
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP,
            self::dropAsk($userId),
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP_DONE,
        );
        self::assertInstanceOf(UserSecondFactorEnrollDropDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * @param int $userId Person whose app it is
     * @param int $authenticatorId App to take off
     * @param string $backupCode Backup code proving the person
     * @return UserSecondFactorRemoveDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function remove(int $userId, int $authenticatorId, string $backupCode): UserSecondFactorRemoveDoneSignalData
    {
        $answer = $this->carry(HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE, new UserSecondFactorRemoveSignalData(
            $userId,
            $authenticatorId,
            BackupCodeGenerator::display($backupCode),
            true,
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE,
            self::ACCEPT_KEY,
            'req-1',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
            null,
        ), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE);
        self::assertInstanceOf(UserSecondFactorRemoveDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * @param int $userId Person whose removal it is
     * @param int $resetId Removal that is due
     * @return UserSecondFactorResetDueDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carryOut(int $userId, int $resetId): UserSecondFactorResetDueDoneSignalData
    {
        $answer = $this->carry(
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE,
            new UserSecondFactorResetDueSignalData($userId, $resetId, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE),
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE,
        );
        self::assertInstanceOf(UserSecondFactorResetDueDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * @param int $userId Person whose removal it is
     * @param int $resetId Removal owing a reminder
     * @param int $staleBefore An announcement at or before this moment is stale (epoch milliseconds)
     * @return UserSecondFactorResetRemindDoneSignalData The agent's answer
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function remind(int $userId, int $resetId, int $staleBefore): UserSecondFactorResetRemindDoneSignalData
    {
        $answer = $this->carry(
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND,
            new UserSecondFactorResetRemindSignalData(
                $userId,
                $resetId,
                $staleBefore,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
            ),
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
        );
        self::assertInstanceOf(UserSecondFactorResetRemindDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * Drops reset rows the case loaded, so the next read sees a raw update.
     */
    private function dropLoadedResets(): void
    {
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
    }

    /**
     * @param int $now Current moment (epoch milliseconds)
     * @param int $notifiedBefore Announcements at or before this moment are stale (epoch milliseconds)
     * @return list<int> Removal ids the reminder sweep would take
     */
    private function reminderIds(int $now, int $notifiedBefore): array
    {
        $ids = [];
        foreach (Hilos::$db->secondFactorResets->reminderDueBy($now, $notifiedBefore) as $reset) {
            $ids[] = (int)$reset->id;
        }

        return $ids;
    }

    /**
     * Sends one frame the way the users library does, hands it to the person's agent and takes its answer.
     *
     * @param string $name Agent-signal name the frame travels under
     * @param SignalDataInterface $frame The frame
     * @param string $replySignal Name the agent answers under, the one the frame carries
     * @return SignalDataInterface The answer's payload
     * @throws HilosException When the frame cannot be queued or the agent fails
     */
    private function carry(string $name, SignalDataInterface $frame, string $replySignal): SignalDataInterface
    {
        // What the fixtures announced on the way in is nobody's business here.
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $this->users->sendToAgent($name, $frame);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        $this->deliverToPerson($signal);

        while (($answer = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($answer->signalName->getName() === $replySignal) {
                self::assertInstanceOf(AgentSignalData::class, $answer->data);

                return $answer->data->data;
            }
        }

        self::fail("Nothing was sent under {$replySignal}");
    }

    /**
     * Runs one step in the users library's own frame, the way its worker would.
     *
     * @param callable(): mixed $step Step to run as the library
     */
    private function inLibrary(callable $step): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), $step);
    }

    /**
     * @param int $userId Person whose code it is
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @return UserSecondFactorProveSignalData The ask the library sends from the profile's code screen
     */
    private static function proveAsk(int $userId, string $code, bool $backupCode): UserSecondFactorProveSignalData
    {
        return new UserSecondFactorProveSignalData(
            userId: $userId,
            code: $code,
            backupCode: $backupCode,
            cancelReset: false,
            trustDevice: false,
            operation: null,
            sessionTokenHash: null,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW,
            successMessage: null,
        );
    }

    /**
     * @param int $userId Person whose unfinished enrolments to drop
     * @return UserSecondFactorEnrollDropSignalData The ask the library sends before it opens the next enrolment
     */
    private static function dropAsk(int $userId): UserSecondFactorEnrollDropSignalData
    {
        return new UserSecondFactorEnrollDropSignalData(
            userId: $userId,
            replySignal: HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: 'req-1',
            action: HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            successMessage: null,
        );
    }

    /**
     * @return string Six digits the first app accepts at none of the steps around now
     */
    private static function wrongCode(): string
    {
        $step = Totp::stepAt(time());
        $taken = [
            Totp::codeAt(self::SECRET_BYTES, $step - 1),
            Totp::codeAt(self::SECRET_BYTES, $step),
            Totp::codeAt(self::SECRET_BYTES, $step + 1),
        ];

        return array_values(array_diff(['000000', '111111', '222222', '333333'], $taken))[0];
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
     * Seeds a confirmed app the way the harness may, past every agent's claim.
     *
     * @param int $userId Owner
     * @param string $secretBytes Raw secret of the app
     * @return SecondFactor The confirmed app
     * @throws HilosException When the row cannot be written
     */
    private static function seedApp(int $userId, string $secretBytes): SecondFactor
    {
        $factor = Hilos::$db->secondFactors->actions->startEnrolment($userId, 'App', Base32::encode($secretBytes));
        $factor->actions->confirm('App');

        return $factor;
    }

    /**
     * Seeds an enrolment that was started and never confirmed, past every agent's claim.
     *
     * The collection action only creates (HIL-1903), so a second one stands beside the first, as the
     * abandoned enrolment of an older start would have left it.
     *
     * @param int $userId Owner
     * @param string $label Name the enrolment started with
     * @throws HilosException When the row cannot be written
     */
    private static function seedUnfinished(int $userId, string $label): void
    {
        Hilos::$db->secondFactors->actions->startEnrolment($userId, $label, Base32::encode(self::OTHER_SECRET_BYTES));
    }

    /**
     * @param int $resetId Removal to read
     * @param string $column Ending column, canceled_at or completed_at
     * @return ?int The moment stored there now, past every loaded row (epoch milliseconds)
     * @throws DatabaseException When the query fails
     */
    private static function endedAt(int $resetId, string $column): ?int
    {
        $row = Database::sql("SELECT `{$column}` AS ended FROM `hilos_second_factor_reset` WHERE `id` = ?", [$resetId])->firstRow();

        return $row === null || $row['ended'] === null ? null : (int)$row['ended'];
    }
}

/** The framework's users library under a test name, with nothing overridden. */
final class SecondFactorEditsTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_second_factor_edits_users_library';
}
