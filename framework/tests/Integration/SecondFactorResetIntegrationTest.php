<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\SecondFactorCommands;
use Hilos\Auth\Library\DTO\SecondFactorResetCancelLinkActionDTO;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesRenewActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorCodesShowActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollConfirmActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorEnrollStartActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorRemoveActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetCancelActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetRequestActionDTO;
use Hilos\Auth\SecondFactor\DTO\ProfileSecondFactorResetWaitSetActionDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorProfileReplyDTO;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorNotificationType;
use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Log\AgentLogStream;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Users\DTO\UserSecondFactorResetRemindDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The profile half of the second factor and its delayed removal, against the real tables (HIL-494).
 *
 * The users library is driven the way a node drives it - a command in - and what it queues is
 * read back: the section fanned to the person's group, the announcements handed to the notifier,
 * the frame telling the holder a factor is gone. What is pinned is what only a database answers:
 * that an app is connected by its first code and the first one issues backup codes, that the last
 * app takes the factor with it, that a removal waits the person's own wait and is canceled by its
 * link once, that the sweep carries a due removal out and reminds of a waiting one, and that a
 * shorter wait waits for the one in force.
 *
 * Connecting an app is the operation 'add_authenticator_app' (HIL-1138). The person of most cases
 * holds no proof, so the gate passes them; the two cases that give the person a password pin what
 * the gate asks of the first app and lets a second one prove on its own. The device-key table is
 * raised beside the session tables because the gate's proof resolver reads it for every person.
 *
 * Every edit of the person's factor is written by the person's agent (HIL-1406): an action runs the
 * way the dispatcher runs a tracked one, the sweep's frames go the same way, and the frames to the
 * agent and its answers are carried across.
 */
final class SecondFactorResetIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    /** Epoch milliseconds of 2026-09-24 09:00:00 UTC, shared by the session row and the reminder bound. */
    private const int SESSION_CREATED_AT = 1790240400000;

    private const string SESSION_TOKEN = 'cc00000000000000000000000000cc94';

    private const string ACCEPT_KEY = 'accept-profile';

    private const int USER_ID = 88;

    /** Address of the password the person holds where a case gives them a proof. */
    private const string EMAIL = 'second-factor@example.test';

    /** Seconds a seeded confirmation has left. */
    private const int CONFIRMED_FOR_SECONDS = 900;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?HilosNotifier $previousNotify = null;

    private SecondFactorResetTestLibrary $library;

    /** @var list<SignalDTO> Signals read off the router and not yet dropped */
    private array $seen = [];

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runPasskeyStub(down: true);
        self::runPasskeyStub(down: false);

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->previousNotify = Hilos::$notify;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        Hilos::$notify = new HilosNotifier();
        $rt = new SecondFactorResetTestRtContext();
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::SESSION_CREATED_AT, null);
        $this->library = new SecondFactorResetTestLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        SourceChangeBus::reset();
        Hilos::$notify = $this->previousNotify;
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        self::runPasskeyStub(down: true);

        parent::tearDown();
    }

    /**
     * The first app is connected by its first code and issues backup codes; the section follows.
     *
     * @throws HilosException When a command fails
     */
    public function testTheFirstAppIsConnectedAndIssuesBackupCodes(): void
    {
        $codes = $this->connectFirstApp();

        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, $codes);
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
        $state = $this->lastState();
        $this->assertCount(1, $state?->authenticators ?? []);
        $this->assertSame(SecondFactorSettings::DEFAULT_BACKUP_CODES, $state?->backupCodesLeft);
    }

    /**
     * A second app starts only with a code from the first.
     *
     * @throws HilosException When a command fails
     */
    public function testASecondAppNeedsAProof(): void
    {
        $this->connectFirstApp();

        $this->expectException(ValidationException::class);
        $this->act(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
    }

    /**
     * The first app needs a live confirmation on both of its steps, and neither writes without it (HIL-1138).
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testTheFirstAppNeedsAConfirmationOnBothSteps(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::PASSWORD, self::EMAIL);

        $this->assertRefusedAsExpired(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
        $this->assertNull(Hilos::$db->secondFactors->unconfirmedOf(self::USER_ID), 'A refused start opens no enrolment');

        $this->confirmAddingAnApp(self::CONFIRMED_FOR_SECONDS);
        $start = $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );

        // The confirmation ran out while the person was scanning the code.
        $this->confirmAddingAnApp(-1);
        $this->assertRefusedAsExpired(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
            new ProfileSecondFactorEnrollConfirmActionDTO(
                (int)$start->authenticatorId,
                Totp::codeAt((string)Base32::decode((string)$start->secret), Totp::stepAt(time())),
                'Phone',
            ),
        );
        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
    }

    /**
     * A second app is let through by the gate on its own code: the connected app is the proof the
     * operation's own step asks for, so nothing is asked twice - and that code is still asked (HIL-1138).
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testASecondAppIsLetThroughOnItsOwnCode(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::PASSWORD, self::EMAIL);
        $this->confirmAddingAnApp(self::CONFIRMED_FOR_SECONDS);
        $secret = $this->connectFirstAppSecret();
        $this->confirmAddingAnApp(-1);

        try {
            $this->act(
                self::ACCEPT_KEY,
                HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
                new ProfileSecondFactorEnrollStartActionDTO(null, false),
            );
            self::fail('A second app without a code from the first must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(SecondFactorMessages::INVALID_CODE, $exception->getMessage(), 'The gate passed; the app code is asked');
        }

        $start = $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO($this->nextCodeOf($secret), false),
        );
        $this->assertNotNull($start->secret);
    }

    /**
     * Started again before its first code, the first app keeps only the latest enrolment: the
     * person's agent drops the earlier one before the next is started, so the form of the first QR
     * is refused as expired and the second is connected (HIL-1903).
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testStartingTheFirstAppAgainLeavesOnlyTheLatestEnrolment(): void
    {
        $first = $this->startApp(null);
        $second = $this->startApp(null);
        $this->assertNotSame($first->authenticatorId, $second->authenticatorId);
        $this->assertSame([(int)$second->authenticatorId], $this->unfinishedIds(), 'The second start dropped the first');

        try {
            $this->confirmApp($first);
            self::fail('The form of the dropped enrolment must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(SecondFactorMessages::SETUP_EXPIRED, $exception->getMessage());
        }

        $confirm = $this->confirmApp($second);
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, $confirm->backupCodes ?? []);
        $connected = Hilos::$db->secondFactors->confirmedOf(self::USER_ID);
        $this->assertSame([(int)$second->authenticatorId], array_map(static fn (SecondFactor $factor): int => (int)$factor->id, $connected));
        $this->assertSame([], $this->unfinishedIds());
    }

    /**
     * A second app started on a code from the first drops only what an earlier start left
     * unfinished: the connected app stays as it was, and one new enrolment is started (HIL-1903).
     *
     * @throws HilosException When a command fails
     */
    public function testASecondAppDropsOnlyTheUnfinishedAndLeavesTheConnectedApp(): void
    {
        $secret = $this->connectFirstAppSecret();
        [$connected] = Hilos::$db->secondFactors->confirmedOf(self::USER_ID);
        // What an abandoned earlier start left, written past every agent's claim.
        $leftover = (int)Hilos::$db->secondFactors->actions->startEnrolment(self::USER_ID, 'Abandoned', Totp::newSecret())->id;

        $this->drain();
        $this->pressDeferred(
            'second-app',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO($this->nextCodeOf($secret), false),
        );
        $this->carryPersonFrames($this->library);
        // Two hops, prove and then drop: the press is answered once, by the second.
        $ack = $this->acks()['second-app'] ?? null;
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $start = SecondFactorProfileReplyDTO::fromArray((array)$ack->reply);

        $this->assertNotSame($leftover, (int)$start->authenticatorId);
        $this->assertSame([(int)$start->authenticatorId], $this->unfinishedIds(), 'Only the new enrolment is unfinished');
        $after = Hilos::$db->secondFactors->confirmedOf(self::USER_ID);
        $this->assertSame([(int)$connected->id], array_map(static fn (SecondFactor $factor): int => (int)$factor->id, $after));
        $this->assertSame('Phone', $after[0]->label);
        $this->assertSame($secret, $after[0]->readSecret());
    }

    /**
     * Two starts pressed at once both reach the person's agent before either answer is back: the
     * answer resumed first starts the enrolment and answers its secret, the other finds that
     * enrolment standing and is refused as expired, and one enrolment is left unfinished (HIL-1903).
     *
     * @throws HilosException When a command or a frame fails
     */
    public function testTwoStartsAtOnceStartOneEnrolmentAndRefuseTheOtherPress(): void
    {
        $this->drain();
        foreach (['start-a', 'start-b'] as $requestId) {
            $this->pressDeferred(
                $requestId,
                HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
                new ProfileSecondFactorEnrollStartActionDTO(null, false),
            );
        }
        $log = $this->logged(fn () => $this->carryPersonFrames($this->library));

        $this->assertStringNotContainsString('Action failed', $log, 'Losing to the other press is a refusal, not a failure');
        $acks = $this->acks();
        $this->assertSame(['start-a', 'start-b'], array_keys($acks));
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $acks['start-a']);
        $started = SecondFactorProfileReplyDTO::fromArray((array)$acks['start-a']->reply);
        $this->assertNotNull($started->secret);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $acks['start-b']);
        $this->assertSame(SecondFactorMessages::SETUP_EXPIRED, $acks['start-b']->reason);
        $this->assertSame([(int)$started->authenticatorId], $this->unfinishedIds());
    }

    /**
     * A read that fails while the person's agent's answer is judged fails the press that waits on it
     * - in the general words, with the detail left in the log, as a continuation that breaks does -
     * and no enrolment is started behind it (HIL-1903).
     *
     * @throws HilosException When a command or a frame fails
     */
    public function testAReadThatFailsWhenTheDropIsAnsweredFailsThePress(): void
    {
        $this->drain();
        $this->pressDeferred(
            'start-a',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
        $this->library->commandsFailure = new DatabaseException('connection lost to the second-factor table');

        $log = $this->logged(fn () => $this->carryPersonFrames($this->library));

        $this->assertStringContainsString('Action failed', $log);
        $this->assertStringContainsString('connection lost to the second-factor table', $log);
        $acks = $this->acks();
        $this->assertSame(['start-a'], array_keys($acks));
        $this->assertInstanceOf(PageActionErrorSignalData::class, $acks['start-a']);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $acks['start-a']->reason);
        $this->assertSame([], $this->unfinishedIds(), 'No enrolment is started behind a press that failed');
    }

    /**
     * A drop the person's agent refuses refuses the start in the agent's words, and no enrolment is
     * started: what stood before stays, and no row is added (HIL-1903).
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testAStartWhoseDropTheAgentRefusesIsRefusedAndStartsNothing(): void
    {
        $leftover = (int)Hilos::$db->secondFactors->actions->startEnrolment(self::USER_ID, 'Abandoned', Totp::newSecret())->id;
        // The agent holds no claim any more, so the truth source refuses the drop it tries.
        TruthSourceRegistry::unregisterAgent($this->personAgent(self::USER_ID)->getId());

        try {
            $this->startApp(null);
            self::fail('A start the agent made no room for must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $exception->getMessage());
        }

        $this->assertSame([$leftover], $this->unfinishedIds());
        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
    }

    /**
     * New backup codes replace the old set through the person's agent: the agent drops the old set,
     * and the new one is issued on its answer and is the answer of the press (HIL-1904).
     *
     * @throws HilosException When a command fails
     */
    public function testNewCodesReplaceTheOldSetThroughThePersonsAgent(): void
    {
        $secret = $this->connectFirstAppSecret($old);
        $this->drain();

        $renewed = $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
            new ProfileSecondFactorCodesRenewActionDTO($this->nextCodeOf($secret), false),
        );

        $codes = $renewed->backupCodes ?? [];
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, $codes);
        $this->assertNull(Hilos::$db->secondFactorBackupCodes->findUnused(self::USER_ID, BackupCodeGenerator::normalize($old[0])));
        $this->assertNotNull(Hilos::$db->secondFactorBackupCodes->findUnused(self::USER_ID, BackupCodeGenerator::normalize($codes[0])));
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
        $this->assertSame(SecondFactorSettings::DEFAULT_BACKUP_CODES, $this->lastState()?->backupCodesLeft);
    }

    /**
     * Two renewals pressed at once both reach the person's agent before either drop is answered:
     * the answer resumed first finds no set and issues one, the other finds that set standing and
     * answers it - one set is left, and both presses are answered with it (HIL-1904).
     *
     * @throws HilosException When a command or a frame fails
     */
    public function testTwoRenewalsAtOnceLeaveOneSetAndAnswerItTwice(): void
    {
        $secret = $this->connectFirstAppSecret($first);
        $this->drain();
        $this->pressDeferred(
            'renew-a',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
            new ProfileSecondFactorCodesRenewActionDTO($this->nextCodeOf($secret), false),
        );
        // Two app codes of one step do not pass the replay guard, so the other press proves with a backup code.
        $this->pressDeferred(
            'renew-b',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
            new ProfileSecondFactorCodesRenewActionDTO($first[0], true),
        );
        $log = $this->logged(fn () => $this->carryPersonFrames($this->library));

        $this->assertStringNotContainsString('Action failed', $log, 'Answering with the standing set is no failure');
        $acks = $this->acks();
        $this->assertSame(['renew-a', 'renew-b'], array_keys($acks));
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $acks['renew-a']);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $acks['renew-b']);
        $codes = SecondFactorProfileReplyDTO::fromArray((array)$acks['renew-a']->reply)->backupCodes ?? [];
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, $codes);
        $this->assertSame($codes, SecondFactorProfileReplyDTO::fromArray((array)$acks['renew-b']->reply)->backupCodes);
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
    }

    /**
     * A drop the person's agent refuses refuses the renewal in the agent's words, and the old codes
     * stay as they were (HIL-1904); that the drop itself was refused shows in the agent's line in its
     * error stream.
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testARenewalWhoseDropTheAgentRefusesKeepsTheOldCodes(): void
    {
        $secret = $this->connectFirstAppSecret($codes);
        $agentId = $this->personAgent(self::USER_ID)->getId();
        // The agent keeps its claim on the apps, so the app code passes, and loses the one on the
        // codes, so the truth source refuses the drop it tries.
        TruthSourceRegistry::unregister(HilosDbContext::secondFactorBackupCodes, $agentId);

        $this->takeAgentErrors($agentId);
        $log = $this->logged(function () use ($secret): void {
            try {
                $this->profileReply(
                    HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
                    new ProfileSecondFactorCodesRenewActionDTO($this->nextCodeOf($secret), false),
                );
                self::fail('A renewal the agent dropped nothing for must be refused');
            } catch (ValidationException $exception) {
                $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $exception->getMessage());
            }
        });

        $this->assertSame(1, substr_count($this->takeAgentErrors($agentId), 'Second factor of #' . self::USER_ID . ' failed:'));
        $this->assertStringNotContainsString('Action failed', $log);
        $this->assertNotNull(Hilos::$db->secondFactorBackupCodes->findUnused(self::USER_ID, BackupCodeGenerator::normalize($codes[0])));
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
    }

    /**
     * A new set that cannot be created after the person's agent dropped the old one fails the press
     * in the general words, with the detail in the log, and the section is fanned again: it shows the
     * person no codes left (HIL-1904).
     *
     * @throws HilosException When a command or a frame fails
     */
    public function testACreationThatFailsAfterTheDropFailsThePressAndShowsNoCodes(): void
    {
        $secret = $this->connectFirstAppSecret();
        $this->drain();
        $this->pressDeferred(
            'renew-a',
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
            new ProfileSecondFactorCodesRenewActionDTO($this->nextCodeOf($secret), false),
        );

        // The library answers in a frame of its own and holds no claim in this case, so the truth
        // source refuses the set it creates; the person's agent answers in its own frame, as before.
        $log = $this->logged(fn () => ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId()),
            fn () => $this->carryPersonFrames($this->library),
        ));

        $this->assertStringContainsString('Action failed', $log);
        $acks = $this->acks();
        $this->assertSame(['renew-a'], array_keys($acks));
        $this->assertInstanceOf(PageActionErrorSignalData::class, $acks['renew-a']);
        $this->assertSame(SignalConstants::ACTION_FAILED_REASON, $acks['renew-a']->reason);
        $this->assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
        $this->assertSame(0, $this->lastState()?->backupCodesLeft);
    }

    /**
     * The last app takes the whole factor with it - and the trust of every browser that skipped it,
     * in the person's agent's transaction (HIL-1407) - and the holder is told.
     *
     * @throws HilosException When a command fails
     */
    public function testTheLastAppTakesTheFactorWithIt(): void
    {
        $secret = $this->connectFirstAppSecret();
        $this->drain();
        $factor = Hilos::$db->secondFactors->confirmedOf(self::USER_ID)[0];
        $sessionId = $this->trustThisBrowser();

        $this->act(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
            new ProfileSecondFactorRemoveActionDTO((int)$factor->id, $this->nextCodeOf($secret), false),
        );

        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertSame([], Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 30));
        $this->assertContains(HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF, $this->queuedNames());
    }

    /**
     * A removal waits the default, announces itself with the link, and the link cancels it once.
     *
     * @throws HilosException When a command fails
     */
    public function testARemovalIsCanceledByItsLinkOnce(): void
    {
        $this->connectFirstApp();
        $this->drain();

        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());

        $reset = Hilos::$db->secondFactorResets->liveOf(self::USER_ID);
        $this->assertNotNull($reset);
        $this->assertEqualsWithDelta(
            SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            $reset->effectiveAt - $reset->requestedAt,
            2 * TimeConstants::MS_PER_SECOND,
        );
        $announcement = $this->announcement(SecondFactorNotificationType::RESET_REQUESTED);
        $url = $announcement?->data['url'] ?? null;
        $this->assertIsString($url, 'The announcement carries the cancel link');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $token = $query[SecondFactorResetNotifier::TOKEN_PARAM] ?? null;
        $this->assertIsString($token);
        $this->assertSame($token, $reset->readCancelToken());
        $this->assertStringContainsString($url, (string)$announcement?->body);
        $this->assertNotContains($token, $reset->toArray());

        $this->act('accept-anyone', HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK, new SecondFactorResetCancelLinkActionDTO($token));
        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));

        $this->expectException(ValidationException::class);
        $this->act('accept-anyone', HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK, new SecondFactorResetCancelLinkActionDTO($token));
    }

    /**
     * dueBy takes a live removal at its deadline and not one millisecond before, a repeated
     * request names that deadline's date, and the profile repeats the stored milliseconds.
     */
    public function testDueBoundaryAndProfileKeepTheStoredMilliseconds(): void
    {
        $this->connectFirstApp();
        $this->drain();
        $this->act(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
            new ProfileSecondFactorResetRequestActionDTO(),
        );

        $stored = Database::sql(
            'SELECT `id`, `requested_at`, `effective_at` FROM `hilos_second_factor_reset` WHERE `user_id` = ?',
            [self::USER_ID],
        )->firstRow();
        $this->assertNotNull($stored);
        $resetId = (int)$stored['id'];
        $requestedAt = (int)$stored['requested_at'];
        $effectiveAt = (int)$stored['effective_at'];
        $profile = $this->lastState()?->reset;
        $this->assertNotNull($profile);
        $this->assertSame($requestedAt, $profile[SecondFactorStateSignalData::requestedAt]);
        $this->assertSame($effectiveAt, $profile[SecondFactorStateSignalData::effectiveAt]);

        $deadline = 1767312000123;
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ? WHERE `id` = ?',
            [$deadline, $resetId],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        try {
            $this->act(
                self::ACCEPT_KEY,
                HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
                new ProfileSecondFactorResetRequestActionDTO(),
            );
            $this->fail('A second removal request must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(
                sprintf(SecondFactorMessages::RESET_ALREADY_REQUESTED, '2026-01-02 00:00:00'),
                $exception->getMessage(),
            );
        }

        $due = [];
        foreach (Hilos::$db->secondFactorResets->dueBy($deadline) as $reset) {
            $due[] = (int)$reset->id;
        }
        $this->assertSame([$resetId], $due);
        $early = [];
        foreach (Hilos::$db->secondFactorResets->dueBy($deadline - 1) as $reset) {
            $early[] = (int)$reset->id;
        }
        $this->assertSame([], $early);
    }

    /**
     * The sweep carries a due removal out and reminds of one still waiting; the removal carried out
     * takes the browser trust with the factor (HIL-1407).
     *
     * @throws HilosException When a command or the sweep fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testTheSweepCarriesOutAndReminds(): void
    {
        $this->connectFirstApp();
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        $firstToken = $this->tokenOf($this->announcement(SecondFactorNotificationType::RESET_REQUESTED));
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `notified_at` = ? WHERE `user_id` = ?',
            [TimeHelper::nowMs() - 2 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->drain();

        $this->tick();
        $reminder = $this->announcement(SecondFactorNotificationType::RESET_REMINDER);
        $this->assertNotNull($reminder);
        $this->assertSame($firstToken, $this->tokenOf($reminder));
        $reminderUrl = $reminder->data['url'] ?? null;
        $this->assertIsString($reminderUrl);
        $this->assertStringContainsString($reminderUrl, (string)$reminder->body);
        $this->assertNotNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID), 'A reminder carries nothing out');

        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ? WHERE `user_id` = ?',
            [TimeHelper::nowMs() - 60 * TimeConstants::MS_PER_SECOND, self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $sessionId = $this->trustThisBrowser();
        $this->tick();

        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 30));
        $this->assertNotNull($this->announcement(SecondFactorNotificationType::RESET_COMPLETED));
    }

    /**
     * The link in a reminder cancels the removal, and does so only once.
     *
     * @throws HilosException When a command or the sweep fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testAReminderLinkCancelsTheRemoval(): void
    {
        $this->connectFirstApp();
        $this->requestAndAgeTheNotice();
        $this->tick();
        $token = $this->tokenOf($this->announcement(SecondFactorNotificationType::RESET_REMINDER));

        $this->act('accept-anyone', HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK, new SecondFactorResetCancelLinkActionDTO($token));
        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));

        $this->expectException(ValidationException::class);
        $this->act('accept-anyone', HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK, new SecondFactorResetCancelLinkActionDTO($token));
    }

    /**
     * The first notice's link still cancels after a reminder has repeated it.
     *
     * @throws HilosException When a command or the sweep fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testTheFirstLinkStillWorksAfterAReminder(): void
    {
        $this->connectFirstApp();
        $firstToken = $this->requestAndAgeTheNotice();
        $this->tick();
        $this->assertSame($firstToken, $this->tokenOf($this->announcement(SecondFactorNotificationType::RESET_REMINDER)));

        $this->act(
            'accept-anyone',
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK,
            new SecondFactorResetCancelLinkActionDTO($firstToken),
        );
        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
    }

    /**
     * A standing removal with no token fails the tick and is not marked sent.
     *
     * @throws HilosException When a command fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testAReminderWithoutATokenFailsTheTick(): void
    {
        $this->connectFirstApp();
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        $stale = TimeHelper::nowMs() - 2 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `cancel_token` = NULL, `notified_at` = ? WHERE `user_id` = ?',
            [$stale, self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->drain();
        $this->library->onStart();

        try {
            $this->library->onTick();
            $this->fail('A standing removal with no cancel token must fail the tick');
        } catch (LogicException) {
        }

        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $reset = Hilos::$db->secondFactorResets->liveOf(self::USER_ID);
        $this->assertNotNull($reset);
        $this->assertSame($stale, $reset->notifiedAt);
        $this->assertNull($reset->readCancelToken());
        $this->assertNull($this->announcement(SecondFactorNotificationType::RESET_REMINDER));
    }

    /**
     * A reminder answer can outlive its removal or its token; both keep the same invariant refusal.
     *
     * @throws HilosException When the fixture row or the answer cannot be handled
     */
    public function testAMarkedReminderWithoutItsRemovalOrTokenKeepsTheRefusal(): void
    {
        $reset = Hilos::$db->secondFactorResets->actions->request(self::USER_ID, 2082758400000, 'cancel-token');
        Database::sqlRun('UPDATE `hilos_second_factor_reset` SET `cancel_token` = NULL WHERE `id` = ?', [(int)$reset->id]);
        self::assertArrayNotHasKey(9999, Hilos::$db->secondFactorResets);
        $this->drain();

        foreach ([9999, (int)$reset->id] as $resetId) {
            $request = new UserSecondFactorResetRemindSignalData(
                self::USER_ID,
                $resetId,
                self::SESSION_CREATED_AT,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
            );
            try {
                $this->library->onSignalAgent(
                    new AgentSignalData(new UserSecondFactorResetRemindDoneSignalData($request, true, null, null, null)),
                    'test',
                    HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
                );
                self::fail('A marked removal without its cancel token must refuse the reminder');
            } catch (LogicException $failure) {
                self::assertSame('A standing second-factor removal ' . $resetId . ' has no cancel token', $failure->getMessage());
            }
            self::assertNull($this->announcement(SecondFactorNotificationType::RESET_REMINDER));
        }
    }

    /**
     * A longer wait applies at once; a shorter one waits for the wait in force.
     *
     * @throws HilosException When a command fails
     */
    public function testAShorterWaitWaitsForTheOneInForce(): void
    {
        $this->setWait(20);
        $this->assertSame(20, $this->lastState()?->resetWait[SecondFactorStateSignalData::days] ?? null);

        $this->setWait(3);
        $state = $this->lastState();
        $this->assertSame(20, $state?->resetWait[SecondFactorStateSignalData::days] ?? null);
        $this->assertSame(3, $state?->resetWait[SecondFactorStateSignalData::pendingDays] ?? null);

        $this->expectException(ValidationException::class);
        $this->setWait(SecondFactorSettings::DEFAULT_RESET_WAIT_MAX_DAYS + 1);
    }

    /**
     * While the administrator has the second factor turned off, the section refuses every action of
     * its own with one sentence, and only calling off the removal under way still works (HIL-1303).
     *
     * @throws HilosException When a command fails for another reason
     */
    public function testWhileTurnedOffTheSectionRefusesAllButCallingOffARemoval(): void
    {
        $secret = $this->connectFirstAppSecret();
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        $factorId = (int)Hilos::$db->secondFactors->confirmedOf(self::USER_ID)[0]->id;
        $this->drain();
        Hilos::$setting = new SettingsAccessor(SecondFactorResetTurnedOffTestCatalog::class);

        $refused = [
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => new ProfileSecondFactorEnrollStartActionDTO(
                $this->nextCodeOf($secret),
                false,
            ),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => new ProfileSecondFactorEnrollConfirmActionDTO(
                $factorId,
                $this->nextCodeOf($secret),
                'Tablet',
            ),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => new ProfileSecondFactorRemoveActionDTO(
                $factorId,
                $this->nextCodeOf($secret),
                false,
            ),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => new ProfileSecondFactorCodesShowActionDTO($this->nextCodeOf($secret), false),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => new ProfileSecondFactorCodesRenewActionDTO($this->nextCodeOf($secret), false),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => new ProfileSecondFactorResetWaitSetActionDTO(20),
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => new ProfileSecondFactorResetRequestActionDTO(),
        ];
        foreach ($refused as $action => $dto) {
            try {
                $this->act(self::ACCEPT_KEY, $action, $dto);
                self::fail("{$action} must be refused while the second factor is turned off");
            } catch (ValidationException $exception) {
                $this->assertSame(SecondFactorMessages::TURNED_OFF_BY_ADMIN, $exception->getMessage(), $action);
            }
        }
        $this->assertCount(1, Hilos::$db->secondFactors->confirmedOf(self::USER_ID), 'The app stays as it was');
        $this->assertNotNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID), 'The removal stays as it was');

        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL, new ProfileSecondFactorResetCancelActionDTO());

        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID), 'Calling off the removal still works');
    }

    /**
     * The letter's link still calls a removal off while the second factor is turned off (HIL-1303).
     *
     * @throws HilosException When a command fails
     */
    public function testTheLinkStillCallsOffARemovalWhileTurnedOff(): void
    {
        $this->connectFirstApp();
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        $token = $this->tokenOf($this->announcement(SecondFactorNotificationType::RESET_REQUESTED));
        Hilos::$setting = new SettingsAccessor(SecondFactorResetTurnedOffTestCatalog::class);

        $this->act('accept-anyone', HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK, new SecondFactorResetCancelLinkActionDTO($token));

        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
    }

    /**
     * A removal asked for before the second factor was turned off is carried out on time (HIL-1303).
     *
     * @throws HilosException When a command or the sweep fails
     * @throws DatabaseException When the backdating write fails
     */
    public function testARemovalAskedBeforeItWasTurnedOffIsCarriedOutOnTime(): void
    {
        $this->connectFirstApp();
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        Hilos::$setting = new SettingsAccessor(SecondFactorResetTurnedOffTestCatalog::class);
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `effective_at` = ? WHERE `user_id` = ?',
            [TimeHelper::nowMs() - 60 * TimeConstants::MS_PER_SECOND, self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->drain();

        $this->tick();

        $this->assertSame([], Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertNull(Hilos::$db->secondFactorResets->liveOf(self::USER_ID));
        $this->assertNotNull($this->announcement(SecondFactorNotificationType::RESET_COMPLETED));
    }

    /**
     * Asks a removal and makes its first notice old enough to remind.
     *
     * @return string Token the first notice carried
     * @throws HilosException When the request fails
     * @throws DatabaseException When the backdating write fails
     */
    private function requestAndAgeTheNotice(): string
    {
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, new ProfileSecondFactorResetRequestActionDTO());
        $token = $this->tokenOf($this->announcement(SecondFactorNotificationType::RESET_REQUESTED));
        Database::sqlRun(
            'UPDATE `hilos_second_factor_reset` SET `notified_at` = ? WHERE `user_id` = ?',
            [TimeHelper::nowMs() - 2 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, self::USER_ID],
        );
        Hilos::$db->secondFactorResets->getObjectCollection()?->clearInMemory();
        Hilos::$db->secondFactorResets->clearCache();
        $this->drain();

        return $token;
    }

    /**
     * @param ?NotificationEmitSignalData $announcement Announcement that should carry the cancel link
     * @return string Token the link carried
     */
    private function tokenOf(?NotificationEmitSignalData $announcement): string
    {
        $url = $announcement?->data['url'] ?? null;
        $this->assertIsString($url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $token = $query[SecondFactorResetNotifier::TOKEN_PARAM] ?? null;
        $this->assertIsString($token);

        return $token;
    }

    /**
     * Connects the first app and answers its backup codes.
     *
     * @return list<string> Backup codes the enrolment issued
     * @throws HilosException When a command fails
     */
    private function connectFirstApp(): array
    {
        $this->connectFirstAppSecret($codes);

        return $codes;
    }

    /**
     * Connects the first app and answers its secret.
     *
     * @param ?list<string> $codes Receives the backup codes the enrolment issued
     * @return string Base32 secret of the app
     * @throws HilosException When a command fails
     */
    private function connectFirstAppSecret(?array &$codes = null): string
    {
        $start = $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO(null, false),
        );
        $secret = (string)$start->secret;
        $confirm = $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
            new ProfileSecondFactorEnrollConfirmActionDTO(
                (int)$start->authenticatorId,
                Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time())),
                'Phone',
            ),
        );
        $codes = $confirm->backupCodes ?? [];

        return $secret;
    }

    /**
     * Submits one profile action and asserts the gate refused it as unconfirmed.
     *
     * @param string $action Action wire name
     * @param ProfileSecondFactorEnrollStartActionDTO|ProfileSecondFactorEnrollConfirmActionDTO $dto Action payload
     * @throws HilosException When the command fails for another reason
     */
    private function assertRefusedAsExpired(
        string $action,
        ProfileSecondFactorEnrollStartActionDTO|ProfileSecondFactorEnrollConfirmActionDTO $dto,
    ): void {
        try {
            $this->act(self::ACCEPT_KEY, $action, $dto);
        } catch (ValidationException $exception) {
            $this->assertSame(StepUpMessages::EXPIRED, $exception->getMessage());

            return;
        }

        self::fail("{$action} must be refused without a confirmation");
    }

    /**
     * Seeds this browser's confirmation of connecting an app, live for or expired since the given seconds.
     *
     * @param int $secondsLeft Seconds the confirmation has left; negative for one that ran out
     * @throws HilosException When the confirmation row cannot be written
     */
    private function confirmAddingAnApp(int $secondsLeft): void
    {
        Hilos::$db->stepUps->actions->confirm(
            ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID,
            StepUpOperationKey::ADD_AUTHENTICATOR_APP,
            TimeHelper::nowMs() + $secondsLeft * TimeConstants::MS_PER_SECOND,
        );
    }

    /**
     * @param string $secret Base32 secret
     * @return string The code of the step after the current one, which the replay guard has not seen
     */
    private function nextCodeOf(string $secret): string
    {
        return Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time()) + 1);
    }

    /**
     * Raises or drops the device-key table the session integration base does not otherwise need.
     *
     * @param bool $down Drop the table when true, create it when false
     * @throws DatabaseException When the stub statement fails
     */
    private static function runPasskeyStub(bool $down): void
    {
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_passkey_credential{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }

    /**
     * @param int $days Wait to choose
     * @throws HilosException When the command fails
     */
    private function setWait(int $days): void
    {
        $this->act(self::ACCEPT_KEY, HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET, new ProfileSecondFactorResetWaitSetActionDTO($days));
    }

    /**
     * Runs one action of the profile or the link the way the dispatcher runs a tracked one, the
     * person's agent writing what it asks.
     *
     * @param string $acceptKey Tab that submits
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @return ?array<string, mixed> Reply the browser was answered with, or null when it was answered with nothing
     * @throws ValidationException When the library or the person's agent refuses
     * @throws HilosException When a command or a frame fails for another reason
     */
    private function act(string $acceptKey, string $action, ActionPayloadDTO $dto): ?array
    {
        return $this->runTracked($this->library, $acceptKey, $action, $dto);
    }

    /**
     * Runs one action of the profile and reads its reply.
     *
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @return SecondFactorProfileReplyDTO What the profile was answered with
     * @throws ValidationException When the library or the person's agent refuses
     * @throws HilosException When a command or a frame fails for another reason
     */
    private function profileReply(string $action, ActionPayloadDTO $dto): SecondFactorProfileReplyDTO
    {
        $reply = $this->act(self::ACCEPT_KEY, $action, $dto);
        $this->assertNotNull($reply, "{$action} answers the profile with a reply");

        return SecondFactorProfileReplyDTO::fromArray($reply);
    }

    /**
     * Starts connecting an app from the profile.
     *
     * @param ?string $proofCode Code of a connected app, or null for the first app
     * @return SecondFactorProfileReplyDTO The enrolment, its secret and otpauth address
     * @throws ValidationException When the library or the person's agent refuses
     * @throws HilosException When a command or a frame fails for another reason
     */
    private function startApp(?string $proofCode): SecondFactorProfileReplyDTO
    {
        return $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
            new ProfileSecondFactorEnrollStartActionDTO($proofCode, false),
        );
    }

    /**
     * Confirms the enrolment a start was answered with, by the current code of its secret.
     *
     * @param SecondFactorProfileReplyDTO $start What the start was answered with
     * @return SecondFactorProfileReplyDTO What the confirmation was answered with
     * @throws ValidationException When the library or the person's agent refuses
     * @throws HilosException When a command or a frame fails for another reason
     */
    private function confirmApp(SecondFactorProfileReplyDTO $start): SecondFactorProfileReplyDTO
    {
        return $this->profileReply(
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
            new ProfileSecondFactorEnrollConfirmActionDTO(
                (int)$start->authenticatorId,
                Totp::codeAt((string)Base32::decode((string)$start->secret), Totp::stepAt(time())),
                'Phone',
            ),
        );
    }

    /**
     * @return list<int> Ids of the person's enrolments started and not confirmed, read afresh
     * @throws HilosException When the lookup fails
     */
    private function unfinishedIds(): array
    {
        $ids = [];
        foreach (Hilos::$db->secondFactors->listByUser(self::USER_ID) as $factor) {
            if ($factor->confirmedAt === null) {
                $ids[] = (int)$factor->id;
            }
        }

        return $ids;
    }

    /**
     * Presses one profile action the way the dispatcher runs a tracked one, and leaves the frame it
     * asks of the person's agent on the queue, undelivered.
     *
     * @param string $requestId Request the press is tracked under
     * @param string $action Action wire name
     * @param ActionPayloadDTO $dto Action payload
     * @throws HilosException When the command fails
     */
    private function pressDeferred(string $requestId, string $action, ActionPayloadDTO $dto): void
    {
        $this->library->beginActionDispatch($requestId);
        try {
            $this->library->onAgentAction(self::ACCEPT_KEY, $action, $dto);
            $this->assertTrue($this->library->actionReplyDeferred(), "{$action} waits on the person's agent");
        } finally {
            $this->library->endActionDispatch();
        }
    }

    /**
     * Runs a step with the log going to a file of its own, and reads back what the step logged.
     *
     * @param Closure(): void $step What to run
     * @return string What the step wrote to the log
     * @throws HilosException When the step fails
     */
    private function logged(Closure $step): string
    {
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-second-factor-reset-log');
        Logger::setLogFile($logFile);
        try {
            $step();

            return (string)file_get_contents($logFile);
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
        }
    }

    /**
     * Reads and clears what an agent wrote to its error stream, which {@see logged()} leaves out: an agent's
     * line goes to a stream file of its own beside the log file, not into the log file.
     *
     * @param string $agentId Agent whose error stream to take
     * @return string What the agent wrote to its error stream since the last take
     */
    private function takeAgentErrors(string $agentId): string
    {
        $path = AgentLogStream::pathFor(sys_get_temp_dir(), $agentId, true);
        if (!is_file($path)) {
            return '';
        }
        $lines = (string)file_get_contents($path);
        unlink($path);

        return $lines;
    }

    /**
     * @return array<string, PageActionSuccessSignalData|PageActionErrorSignalData> Action acks queued since the last drain, by request;
     *     a request answered twice fails the case, so an early empty success in a later hop is not taken for the last word
     */
    private function acks(): array
    {
        $acks = [];
        foreach ($this->queued() as $signal) {
            $payload = $signal->data instanceof WebSocketSignalData ? $signal->data->data : null;
            if ($payload instanceof PageActionSuccessSignalData || $payload instanceof PageActionErrorSignalData) {
                $this->assertNotNull($payload->requestId);
                $this->assertArrayNotHasKey($payload->requestId, $acks, "Request {$payload->requestId} is answered once");
                $acks[$payload->requestId] = $payload;
            }
        }

        return $acks;
    }

    /**
     * Runs the users library's tick from a fresh start and carries the sweep's frames to the person's agent and back.
     *
     * @throws HilosException When the sweep or a frame fails
     */
    private function tick(): void
    {
        $this->library->onStart();
        $this->library->onTick();
        $this->carryPersonFrames($this->library);
    }

    /**
     * @return ?SecondFactorStateSignalData The last section fanned since the last drain
     */
    private function lastState(): ?SecondFactorStateSignalData
    {
        $state = null;
        foreach ($this->queued() as $signal) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof SecondFactorStateSignalData) {
                $state = $signal->data->data;
            }
        }

        return $state;
    }

    /**
     * @param string $type Notification type
     * @return ?NotificationEmitSignalData The last announcement of that type since the last drain
     */
    private function announcement(string $type): ?NotificationEmitSignalData
    {
        $found = null;
        foreach ($this->queued() as $signal) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof NotificationEmitSignalData
                && $payload->data->type === $type) {
                $found = $payload->data;
            }
        }

        return $found;
    }

    /**
     * @return list<string> Names of the signals queued since the last drain
     */
    private function queuedNames(): array
    {
        return array_map(static fn ($signal): string => $signal->signalName->getName(), $this->queued());
    }

    /**
     * @return list<SignalDTO> Every signal queued since the last drain
     */
    private function queued(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->seen[] = $signal;
        }

        return $this->seen;
    }

    /**
     * Drops every signal queued so far.
     */
    private function drain(): void
    {
        $this->queued();
        $this->seen = [];
    }

    /**
     * Trusts the person's browser to skip the factor for a month, the way a sign-in let through does.
     *
     * @return int Session row of the trusted browser
     * @throws HilosException When the session cannot be read or the trust cannot be written
     */
    private function trustThisBrowser(): int
    {
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        Hilos::$db->secondFactorTrusts->actions->trust(
            $sessionId,
            self::USER_ID,
            TimeHelper::nowMs() + 30 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
        );
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 30));

        return $sessionId;
    }
}

/**
 * The second-factor catalog with the second factor turned off for the installation (HIL-1303).
 */
final class SecondFactorResetTurnedOffTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Catalog with the second factor turned off
     */
    public static function getCatalog(): array
    {
        $catalog = SecondFactorSettingsCatalog::getCatalog();
        $catalog[SecondFactorSettings::ENABLED_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = false;

        return $catalog;
    }
}

/**
 * Runtime with the one signed-in tab of the person under test.
 */
final class SecondFactorResetTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SecondFactorResetTestConnections::init();
        $connections->add(SecondFactorResetTestConnection::create('accept-profile', 88, 'cc00000000000000000000000000cc94'));
        $this->_stateCollections[SecondFactorResetTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Users library of the fixture project, which creates and names nobody.
 */
final class SecondFactorResetTestLibrary extends AbstractUsersLibraryAgent
{
    /** @var ?Throwable Failure every use of the second-factor commands raises, or null while they are served */
    public ?Throwable $commandsFailure = null;

    /**
     * @return SecondFactorCommands The commands, unless a case made them fail
     * @throws Throwable The failure a case armed
     */
    protected function secondFactorCommands(): SecondFactorCommands
    {
        if ($this->commandsFailure !== null) {
            throw $this->commandsFailure;
        }

        return parent::secondFactorCommands();
    }

    /**
     * @param string $displayName Name the new account would be created with
     * @param ?string $themePick Guest theme choice, or null when not chosen
     * @return int Never returns
     * @throws LogicException Always: these cases register nobody
     */
    public function createUser(string $displayName, ?string $themePick): int
    {
        throw new LogicException('the second-factor cases register nobody');
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
final class SecondFactorResetTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'secondFactorResetTestConnections';

    public const string STATE_CLASS = SecondFactorResetTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SecondFactorResetTestConnection extends HilosSessionConnection
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
