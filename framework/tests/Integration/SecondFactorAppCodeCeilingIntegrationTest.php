<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\SecondFactorCommands;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorNotificationType;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\SecondFactorUnlockCommandConstants;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Item\SecondFactorSetting as ObjectSecondFactorSetting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationTypeRegistry;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * One ceiling on wrong app codes per person, wherever the code is typed (HIL-1285).
 *
 * The session holder and the users library are driven the way a node drives them, and every
 * frame the library queues for the holder is handed across. Three doors take app codes here:
 * the code step of a sign-in on one browser, and the profile and the confirmation of an
 * operation on another browser the person is signed in on. What is pinned is what only the
 * row of the person answers: that misses on all three add up, that the lock closes all three
 * and refuses even a right code, that a new sign-in neither lifts the lock nor clears the
 * count, that backup codes stay open and uncounted, that the ladder climbs and forgets, that
 * each lock is told to the person once, and that the operator's command lifts it.
 */
final class SecondFactorAppCodeCeilingIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string CREATED_AT = '2026-10-08 09:00:00';

    /** Browser on the code step of a sign-in. */
    public const string SIGN_IN_TOKEN = 'dd00000000000000000000000000dd85';

    public const string SIGN_IN_KEY = 'accept-ceiling-sign-in';

    /** Browser the person is signed in on: the profile and the operations. */
    public const string SIGNED_TOKEN = 'ee00000000000000000000000000ee85';

    public const string SIGNED_KEY = 'accept-ceiling-signed';

    private const string REQUEST_ID = 'request-ceiling';

    public const int USER_ID = 1285;

    /** The RFC 6238 test secret, as the authenticator enrolled here holds it. */
    private const string SECRET_BYTES = '12345678901234567890';

    /** Wrong app codes that lock, as the environment sets them by default. */
    private const int CEILING = 10;

    /** Wrong codes the code step of one sign-in takes before it gives up its wait. */
    private const int WAIT_MISSES = 4;

    /** A backup code issued to the person. */
    private const string BACKUP_CODE = 'abcdefghjk';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?HilosNotifier $previousNotify = null;

    private SecondFactorCeilingTestHolder $holder;

    private SecondFactorCeilingTestLibrary $library;

    /** @var list<SignalDTO> Signals read off the router */
    private array $seen = [];

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->previousNotify = Hilos::$notify;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(SecondFactorCeilingTestSettingsCatalog::class);
        Hilos::$notify = new HilosNotifier();
        $rt = new SecondFactorCeilingTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateRecoveryWaiter::RT_COLLECTION);

        self::seedSession(self::SIGN_IN_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::SIGNED_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $this->holder = new SecondFactorCeilingTestHolder();
        $this->library = new SecondFactorCeilingTestLibrary();
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
        Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, [self::BACKUP_CODE]);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateRecoveryWaiter::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$notify = $this->previousNotify;
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * Ten misses spread over the sign-in, the profile and an operation lock all three, a right code too.
     *
     * @throws HilosException When a frame or a command fails for another reason
     */
    public function testMissesOnTheThreeDoorsAddUpAndTheLockClosesThemAll(): void
    {
        $this->holdSignIn();
        for ($miss = 0; $miss < self::WAIT_MISSES; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->signInCode($this->wrongCode()));
        }
        for ($miss = 0; $miss < 3; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->profileCode($this->wrongCode()));
        }
        $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->operationCode($this->wrongCode()));
        $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->operationCode($this->wrongCode()));
        $this->assertSame(self::CEILING - 1, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeMisses);

        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->operationCode($this->wrongCode()));

        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->signInCode($this->currentCode()));
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->profileCode($this->currentCode()));
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->operationCode($this->currentCode()));
        $setting = Hilos::$db->secondFactorSettings[self::USER_ID];
        $this->assertSame(0, $setting?->appCodeMisses, 'Nothing counts under the lock');
        $this->assertSame(1, $setting?->appCodeLockStep);
        $session = Hilos::$db->sessions->findByToken(self::SIGN_IN_TOKEN);
        $this->assertSame(self::USER_ID, $session?->pendingSecondFactorUserId, 'The lock does not end the wait');
        $this->assertSame(self::WAIT_MISSES, $session?->pendingSecondFactorAttempts, 'Nor is it a miss of the wait');
        $this->assertFalse(Hilos::$db->stepUps->isConfirmed(
            ProtectedModeRuntime::hashSessionToken(self::SIGNED_TOKEN),
            self::USER_ID,
            StepUpOperationKey::CHANGE_PASSWORD,
        ));
    }

    /**
     * A new sign-in with the password starts the wait's own count again, not the person's, and leaves the lock.
     *
     * @throws HilosException When a frame or a command fails for another reason
     */
    public function testANewSignInNeitherClearsTheCountNorLiftsTheLock(): void
    {
        $this->holdSignIn();
        for ($miss = 0; $miss < self::WAIT_MISSES; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->signInCode($this->wrongCode()));
        }

        $this->holdSignIn();
        $this->assertSame(0, Hilos::$db->sessions->findByToken(self::SIGN_IN_TOKEN)?->pendingSecondFactorAttempts);
        $this->assertSame(self::WAIT_MISSES, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeMisses);
        for ($miss = 0; $miss < self::WAIT_MISSES; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->signInCode($this->wrongCode()));
        }

        $this->holdSignIn();
        $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->signInCode($this->wrongCode()));
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->signInCode($this->wrongCode()));

        $this->holdSignIn();
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->signInCode($this->currentCode()));
        $this->assertSame(1, $this->lockNotices());
    }

    /**
     * A backup code passes under the lock, and a wrong one is neither counted nor told as the lock.
     *
     * @throws HilosException When a frame or a command fails for another reason
     */
    public function testBackupCodesStayOutsideTheLockAndTheCount(): void
    {
        for ($miss = 0; $miss < 3; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->profileCode('ZZZZZ-ZZZZZ', backupCode: true));
        }
        $this->assertSame(0, (int)Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeMisses);

        $this->lockByProfile();
        $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->profileCode('ZZZZZ-ZZZZZ', backupCode: true));

        $this->holdSignIn();
        $this->signInCode(BackupCodeGenerator::display(self::BACKUP_CODE), backupCode: true);
        $this->forwardToHolder();
        $this->assertNull(self::sessionRow(self::SIGN_IN_TOKEN), 'The backup code signed the person in and rotated the token');
    }

    /**
     * The first code of a new app is checked outside the count: its secret was just shown to the person.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testTheFirstCodeOfANewAppIsNotCounted(): void
    {
        Hilos::$db->secondFactors->actions->startEnrolment(self::USER_ID, 'Tablet', Base32::encode('09876543210987654321'));
        $commands = new SecondFactorCommands($this->library);

        for ($miss = 0; $miss < self::CEILING; $miss++) {
            $this->assertRefused(
                SecondFactorMessages::INVALID_CODE,
                fn () => $commands->confirmEnrolment(self::USER_ID, $this->wrongCode('09876543210987654321'), 'Tablet', null),
            );
        }

        $this->assertNull(Hilos::$db->secondFactorSettings[self::USER_ID], 'No miss was counted, so no row was made');
    }

    /**
     * A lock within a day of the last one climbs a step; a day without one starts the ladder again.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testTheLadderClimbsWithinADayAndStartsAgainAfterOne(): void
    {
        $this->lockByProfile();
        $this->assertSame(1, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeLockStep);

        $this->endLockSecondsAgo(3600);
        $this->lockByProfile('1 h');
        $setting = Hilos::$db->secondFactorSettings[self::USER_ID];
        $this->assertSame(2, $setting?->appCodeLockStep);
        $this->assertEqualsWithDelta(time() + 3600, strtotime((string)$setting?->appCodeLockedUntil), 5);

        $this->endLockSecondsAgo(86400 + 60);
        $this->lockByProfile();
        $this->assertSame(1, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeLockStep);
        $this->assertSame(3, $this->lockNotices());
    }

    /**
     * Each lock is told once, on a type no channel setting mutes; a refusal under the lock tells nothing.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testEachLockIsToldOnceOnAMandatoryType(): void
    {
        $this->lockByProfile();
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->profileCode($this->wrongCode()));
        $this->assertRefused($this->lockedFor('15 min'), fn () => $this->profileCode($this->currentCode()));

        $this->assertSame(1, $this->lockNotices());
        $this->assertTrue(NotificationTypeRegistry::isMandatory(SecondFactorNotificationType::APP_CODES_LOCKED));
    }

    /**
     * Of two misses that both reached the ceiling, one puts the lock and the other finds it put.
     *
     * @throws HilosException When a write fails
     */
    public function testOnlyOneOfTwoMissesAtTheCeilingPutsTheLock(): void
    {
        $actions = Hilos::$db->secondFactorSettings->actions;
        for ($miss = 1; $miss <= self::CEILING; $miss++) {
            $this->assertSame($miss, $actions->countAppCodeMiss(self::USER_ID, 86400));
        }
        $until = date('Y-m-d H:i:s', time() + 900);

        $this->assertTrue($actions->lockAppCodes(self::USER_ID, self::CEILING, 1, $until));
        $this->assertFalse($actions->lockAppCodes(self::USER_ID, self::CEILING, 1, $until));
        $this->assertSame(0, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeMisses);
    }

    /**
     * A window begun a day ago is over: the next miss starts the count again from one.
     *
     * @throws HilosException When a write fails
     */
    public function testAMissADayAfterTheWindowOpenedStartsANewOne(): void
    {
        $actions = Hilos::$db->secondFactorSettings->actions;
        for ($miss = 0; $miss < self::CEILING - 1; $miss++) {
            $actions->countAppCodeMiss(self::USER_ID, 86400);
        }
        $setting = $this->settingObject();
        $setting->appCodeMissesFrom = date('Y-m-d H:i:s', time() - 86400);
        $setting->sync();

        $this->assertSame(1, $actions->countAppCodeMiss(self::USER_ID, 86400));
    }

    /**
     * The operator's command lifts the lock, its step and the count, says until when it held, and the app works again.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testTheCommandLiftsTheLockAndTheAppCodePassesAgain(): void
    {
        $this->lockByProfile();
        $until = Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeLockedUntil;
        $this->assertNotNull($until);

        $reply = $this->unlock(self::USER_ID);

        $this->assertTrue($reply->isOk());
        $this->assertSame([
            SecondFactorUnlockCommandConstants::FIELD_USER_ID => self::USER_ID,
            SecondFactorUnlockCommandConstants::FIELD_WAS_LOCKED => true,
            SecondFactorUnlockCommandConstants::FIELD_LOCKED_UNTIL => $until,
        ], $reply->payload);
        $setting = Hilos::$db->secondFactorSettings[self::USER_ID];
        $this->assertSame([0, null, 0, null], [
            $setting?->appCodeMisses,
            $setting?->appCodeMissesFrom,
            $setting?->appCodeLockStep,
            $setting?->appCodeLockedUntil,
        ]);
        $this->profileCode($this->currentCode());
        $this->assertSame(1, $this->lockNotices(), 'The lift is not announced');
    }

    /**
     * A person without a lock is answered ok, and the misses counted so far are cleared.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testTheCommandOnAPersonWithoutALockClearsTheCount(): void
    {
        $reply = $this->unlock(self::USER_ID);
        $this->assertTrue($reply->isOk());
        $this->assertFalse($reply->payload[SecondFactorUnlockCommandConstants::FIELD_WAS_LOCKED] ?? null);
        $this->assertNull(Hilos::$db->secondFactorSettings[self::USER_ID], 'A person with no row gets none');

        for ($miss = 0; $miss < 3; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->profileCode($this->wrongCode()));
        }
        $reply = $this->unlock((string)self::USER_ID);

        $this->assertSame([
            SecondFactorUnlockCommandConstants::FIELD_USER_ID => self::USER_ID,
            SecondFactorUnlockCommandConstants::FIELD_WAS_LOCKED => false,
            SecondFactorUnlockCommandConstants::FIELD_LOCKED_UNTIL => null,
        ], $reply->payload);
        $this->assertSame(0, Hilos::$db->secondFactorSettings[self::USER_ID]?->appCodeMisses);
    }

    /**
     * A person who does not exist, or no person at all, is refused by name.
     *
     * @throws HilosException When the command fails for another reason
     */
    public function testTheCommandRefusesAnUnknownPerson(): void
    {
        $reply = $this->unlock(9999);
        $this->assertFalse($reply->isOk());
        $this->assertSame('No user #9999', $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null);

        $this->assertFalse($this->unlock('five')->isOk());
        $this->assertFalse($this->unlock(0)->isOk());
    }

    /**
     * The removal asked from the code step passes the throttle; the one asked from the profile does not.
     */
    public function testTheRemovalAskedFromTheCodeStepIsThrottled(): void
    {
        $this->assertContains(HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST, AbstractUsersLibraryAgent::THROTTLED_ACTIONS);
        $this->assertNotContains(HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, AbstractUsersLibraryAgent::THROTTLED_ACTIONS);
    }

    /**
     * Locks the person's app codes by ten wrong codes in the profile, the tenth refused with the lock.
     *
     * @param string $left Time left the tenth refusal names
     * @throws HilosException When a command fails for another reason
     */
    private function lockByProfile(string $left = '15 min'): void
    {
        for ($miss = 1; $miss < self::CEILING; $miss++) {
            $this->assertRefused(SecondFactorMessages::INVALID_CODE, fn () => $this->profileCode($this->wrongCode()));
        }

        $this->assertRefused($this->lockedFor($left), fn () => $this->profileCode($this->wrongCode()));
    }

    /**
     * Sends the library the operator's command and reads its one reply.
     *
     * @param int|string $userId Person as the payload carries them
     * @return CommandReplyDTO The reply
     * @throws HilosException When the library fails on the command
     */
    private function unlock(int|string $userId): CommandReplyDTO
    {
        $correlationId = 'unlock-' . $userId;
        $this->library->onSignalCommand(
            new CommandRequestDTO($correlationId, CliCommands::SECOND_FACTOR_UNLOCK, [
                SecondFactorUnlockCommandConstants::FIELD_USER_ID => $userId,
            ]),
            '',
            '',
        );

        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->seen[] = $signal;
            if ($signal->signalName->getName() === $correlationId && $signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies, 'The command is answered exactly once');

        return $replies[0];
    }

    /**
     * Moves the end of the person's lock into the past, as if that much time had passed since.
     *
     * @param int $seconds How long ago the lock ended
     * @throws HilosException When the write fails
     */
    private function endLockSecondsAgo(int $seconds): void
    {
        $setting = $this->settingObject();
        $setting->appCodeLockedUntil = date('Y-m-d H:i:s', time() - $seconds);
        $setting->sync();
    }

    /**
     * @return ObjectSecondFactorSetting The person's row as the library holds it
     * @throws HilosException When the collection cannot be read
     */
    private function settingObject(): ObjectSecondFactorSetting
    {
        /** @var ObjectSecondFactorSettings $collection */
        $collection = Hilos::$db->getObjectCollection(HilosDbContext::secondFactorSettings);
        $setting = $collection[self::USER_ID];
        $this->assertNotNull($setting);

        return $setting;
    }

    /**
     * Asserts a call is refused with exactly that sentence.
     *
     * @param string $message Sentence the refusal must carry
     * @param callable(): mixed $call Call to refuse
     */
    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $refusal) {
            $this->assertSame($message, $refusal->getMessage());
            $this->forwardToHolder();

            return;
        }

        $this->fail("Expected the refusal '{$message}'");
    }

    /**
     * @param string $left Time left as the refusal names it
     * @return string The refusal of a locked app code
     */
    private function lockedFor(string $left): string
    {
        return sprintf(SecondFactorMessages::APP_CODES_LOCKED, $left);
    }

    /**
     * Hands the holder a password sign-in of the person on the sign-in browser, which waits on the code step.
     *
     * @throws HilosException When the grant fails
     */
    private function holdSignIn(): void
    {
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SIGN_IN_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::SIGN_IN_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_LOGIN,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
        $this->forwardToHolder();
    }

    /**
     * Submits a code on the code step of the sign-in.
     *
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @throws HilosException When the command refuses or fails
     */
    private function signInCode(string $code, bool $backupCode = false): void
    {
        $this->library->onAgentAction(
            self::SIGN_IN_KEY,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR,
            new ConfirmSecondFactorActionDTO($code, $backupCode, false),
        );
    }

    /**
     * Submits a code to show the backup codes in the profile.
     *
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @throws HilosException When the command refuses or fails
     */
    private function profileCode(string $code, bool $backupCode = false): void
    {
        $this->library->onAgentAction(
            self::SIGNED_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW,
            new ProfileSecondFactorCodesShowActionDTO($code, $backupCode),
        );
    }

    /**
     * Submits a code to confirm the change of the password.
     *
     * @param string $code Code as typed
     * @throws HilosException When the command refuses or fails
     */
    private function operationCode(string $code): void
    {
        $this->library->onAgentAction(
            self::SIGNED_KEY,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO(StepUpOperationKey::CHANGE_PASSWORD, StepUpMethod::SECOND_FACTOR, $code, false, '', null),
        );
    }

    /**
     * @return string The code of the enrolled authenticator right now
     */
    private function currentCode(): string
    {
        return Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time()));
    }

    /**
     * A code the authenticator accepts at none of the steps around now.
     *
     * @param string $secret Raw secret of the authenticator
     * @return string Six digits that are not its code
     */
    private function wrongCode(string $secret = self::SECRET_BYTES): string
    {
        $step = Totp::stepAt(time());
        $taken = [Totp::codeAt($secret, $step - 1), Totp::codeAt($secret, $step), Totp::codeAt($secret, $step + 1)];
        foreach (['000000', '111111', '222222', '333333'] as $candidate) {
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        throw new LogicException('four candidates cannot all be codes of three steps');
    }

    /**
     * @return int Lock announcements handed to the notifier so far
     */
    private function lockNotices(): int
    {
        $this->forwardToHolder();
        $notices = 0;
        foreach ($this->seen as $signal) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof NotificationEmitSignalData
                && $payload->data->type === SecondFactorNotificationType::APP_CODES_LOCKED) {
                $notices++;
            }
        }

        return $notices;
    }

    /**
     * Reads what the router queued, keeping it, and hands the frames meant for the holder across.
     *
     * @throws HilosException When the holder fails on a frame
     */
    private function forwardToHolder(): void
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->seen[] = $signal;
            $name = $signal->signalName->getName();
            if ($signal->data instanceof AgentSignalData && isset(AbstractSessionsLibraryAgent::AGENT_SIGNALS[$name])) {
                $frames[] = [$name, $signal->data];
            }
        }
        foreach ($frames as [$name, $data]) {
            $this->holder->onSignalAgent($data, 'test', $name);
        }
    }
}

/**
 * Settings the sign-in, the profile and the confirmation of an operation read.
 */
final class SecondFactorCeilingTestSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(StepUpSettingsCatalog::getCatalog(), SecondFactorSettingsCatalog::getCatalog());
    }
}

/**
 * Runtime with the sign-in feature, the browser on the code step and the browser signed in.
 */
final class SecondFactorCeilingTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SecondFactorCeilingTestConnections::init();
        $connections->add(SecondFactorCeilingTestConnection::create(
            SecondFactorAppCodeCeilingIntegrationTest::SIGN_IN_KEY,
            null,
            SecondFactorAppCodeCeilingIntegrationTest::SIGN_IN_TOKEN,
        ));
        $connections->add(SecondFactorCeilingTestConnection::create(
            SecondFactorAppCodeCeilingIntegrationTest::SIGNED_KEY,
            SecondFactorAppCodeCeilingIntegrationTest::USER_ID,
            SecondFactorAppCodeCeilingIntegrationTest::SIGNED_TOKEN,
        ));
        $this->_stateCollections[SecondFactorCeilingTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Sessions library of the fixture project.
 */
final class SecondFactorCeilingTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }

    /**
     * @param int $survivorUserId Surviving account
     * @param int $loserUserId Folded account
     * @return array<string, int> This fixture has no project rows
     */
    protected function applyAccountMerge(int $survivorUserId, int $loserUserId): array
    {
        return [];
    }
}

/**
 * Users library of the fixture project, which creates and names nobody.
 */
final class SecondFactorCeilingTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Name the new account would be created with
     * @return int Never returns
     * @throws LogicException Always: these cases register nobody
     */
    public function createUser(string $displayName): int
    {
        throw new LogicException('the ceiling cases register nobody');
    }

    /**
     * @param int $userId Account to name
     * @return ?string Always null
     */
    public function displayNameOf(int $userId): ?string
    {
        return null;
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class SecondFactorCeilingTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'secondFactorCeilingTestConnections';

    public const string STATE_CLASS = SecondFactorCeilingTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SecondFactorCeilingTestConnection extends HilosSessionConnection
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
     * @return array<string, mixed> Own fields, of which this fixture has none
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
