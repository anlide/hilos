<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustDaysApplySignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupConfirmActionDTO;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\SecondFactor\SecondFactorGate;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\CliCommands;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\SecondFactorFate;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The sign-in half of the second factor, against the real tables (HIL-494).
 *
 * The session holder and the users library are driven the way a node drives them - a frame in,
 * a command in - and every frame one of them queues for the other is handed across. What is
 * pinned is what only a database can answer: that a proven sign-in waits on the session row
 * rather than signing in, that a code passes once and a backup code burns once, that the wait
 * gives up after its ceiling, that a trusted browser skips the step, that recovering a password
 * by mail does not sign in past the factor, and that an administrator's requirement enrols on
 * the way in. A code is checked by the person's agent, and its frames are carried both ways
 * (HIL-1406).
 */
final class SecondFactorSignInIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const string CREATED_AT = '2026-09-24 09:00:00';

    private const string SESSION_TOKEN = 'bb00000000000000000000000000bb94';

    private const string ACCEPT_KEY = 'accept-sf';

    private const string SIBLING_KEY = 'accept-sf-sibling';

    private const string REQUEST_ID = 'request-sf';

    private const int USER_ID = 77;

    /** The RFC 6238 test secret, as the authenticator enrolled here holds it. */
    private const string SECRET_BYTES = '12345678901234567890';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?SettingsAccessor $previousSetting = null;

    private SecondFactorTestHolder $holder;

    private SecondFactorTestLibrary $library;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Person')", [self::USER_ID]);

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        $rt = new SecondFactorTestRtContext();
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

        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $this->holder = new SecondFactorTestHolder();
        $this->library = new SecondFactorTestLibrary();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        RtTruthSourceRegistry::unregisterDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateRecoveryWaiter::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$setting = $this->previousSetting;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * A person without a second factor signs in as before.
     *
     * @throws HilosException When the grant fails
     */
    public function testAPersonWithoutASecondFactorIsSignedIn(): void
    {
        $this->grant();

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN), 'The token rotated on the sign-in');
    }

    /**
     * A proven sign-in of a person with a factor waits on the code step, in every tab.
     *
     * @throws HilosException When the grant fails
     */
    public function testAProvenSignInWaitsOnTheCodeStep(): void
    {
        $this->enrol();

        $this->grant();

        $frames = $this->stateFrames();
        $this->assertCount(2, $frames, 'The sibling tab is told, and the submitting tab is answered');
        $this->assertSame([self::SIBLING_KEY], $frames[0]->acceptKeys);
        $this->assertSame([self::ACCEPT_KEY], $frames[1]->acceptKeys);
        $this->assertNull($frames[1]->userId, 'Nobody is signed in yet');
        $this->assertSame(
            AuthFlowStep::SECOND_FACTOR,
            $frames[1]->pendingAuthStep[HandshakeResponseSignalData::step] ?? null,
        );
        $outcome = AuthFlowOutcome::fromArray((array)$frames[1]->outcome);
        $this->assertSame(AuthFlowStep::SECOND_FACTOR, $outcome->step);
        $this->assertSame(SecondFactorSettings::DEFAULT_TRUST_DAYS, $outcome->secondFactor?->trustDeviceDays);
        $session = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN);
        $this->assertSame(self::USER_ID, $session?->pendingSecondFactorUserId);
        $this->assertSame(SecondFactorPendingMode::VERIFY, $session?->pendingSecondFactorMode);
    }

    /**
     * The code from the app lets the sign-in through, and the same code does not pass twice.
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testTheAppCodePassesOnce(): void
    {
        $this->enrol();
        $this->grant();
        $this->drain();
        $code = $this->currentCode();

        $this->confirm($code);
        $this->forwardToHolder();

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN), 'The sign-in went through and rotated the token');

        $this->reseedAndHold();
        $this->expectException(ValidationException::class);
        $this->confirm($code);
    }

    /**
     * A backup code burns on its first use.
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testABackupCodeBurnsOnce(): void
    {
        $this->enrol();
        Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, ['abcdefghjk']);
        $this->grant();
        $this->drain();

        $this->confirm('ABCDE-FGHJK', backupCode: true);
        $this->forwardToHolder();
        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);

        $this->reseedAndHold();
        $this->expectException(ValidationException::class);
        $this->confirm(BackupCodeGenerator::display('abcdefghjk'), backupCode: true);
    }

    /** @throws HilosException When the merge or a sign-in step fails */
    public function testMergedProtectionGuardsTheSurvivorsNextSignIn(): void
    {
        Database::sqlRun((string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_passkey_credential.sql',
        ));
        try {
            Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (78, 'Folded')");
            Hilos::$db->secondFactors->actions->startEnrolment(78, 'Transferred', Base32::encode(self::SECRET_BYTES))
                ->actions->confirm('Transferred');
            Hilos::$db->secondFactorBackupCodes->actions->issueSet(78, ['abcdefghjk']);
            $this->holder->onSignalCommand(new CommandRequestDTO('merge-sign-in', CliCommands::ACCOUNT_MERGE, [
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => self::USER_ID,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => 78,
                AccountMergeCommandConstants::FIELD_SECOND_FACTOR_FATE => SecondFactorFate::BOTH->value,
            ]), '', '');
            $this->drain();
            $this->assertSame(self::USER_ID, Hilos::$db->userMerges[78]?->survivorUserId);
            $this->grant();
            $this->assertNull($this->lastStateFrame()?->userId);
            $this->assertSame(SecondFactorPendingMode::VERIFY, Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorMode);
            $this->drain();
            $this->confirm($this->currentCode());
            $this->forwardToHolder();
            $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);

            $this->reseedAndHold();
            $this->confirm('ABCDE-FGHJK', backupCode: true);
            $this->forwardToHolder();
            $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);
            $this->reseedAndHold();
            $this->expectException(ValidationException::class);
            $this->confirm('ABCDE-FGHJK', backupCode: true);
        } finally {
            Database::sqlRun('DROP TABLE IF EXISTS hilos_passkey_credential');
        }
    }

    /**
     * The fifth wrong code lets the wait go and sends the tabs back to the address field.
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testTheCeilingOfWrongCodesEndsTheWait(): void
    {
        $this->enrol();
        $this->grant();
        $this->drain();

        for ($miss = 0; $miss < 5; $miss++) {
            try {
                $this->confirm('000000');
                $this->fail('A wrong code must be refused');
            } catch (ValidationException) {
                $this->forwardToHolder();
            }
        }

        $this->assertNull(Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorUserId);
        $frames = $this->stateFrames();
        $step = $frames === [] ? null : end($frames)->pendingAuthStep;
        $this->assertSame(AuthFlowStep::IDENTIFIER, $step[HandshakeResponseSignalData::step] ?? null);
        $this->assertSame(
            AuthFlowOutcome::CODE_SECOND_FACTOR_ATTEMPTS,
            $step[HandshakeResponseSignalData::code] ?? null,
        );
    }

    /**
     * A browser the person asked to trust skips the step on the next sign-in.
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testATrustedBrowserSkipsTheStep(): void
    {
        $this->enrol();
        $this->grant();
        $this->drain();
        $this->confirm($this->currentCode(), trustDevice: true);
        $this->forwardToHolder();
        $rotated = $this->lastStateFrame()?->sessionToken;
        $this->assertNotNull($rotated);

        $sessionId = Hilos::$db->sessions->findByToken($rotated)->id;
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new SessionRebindSignalData(
                sessionToken: $rotated,
                userId: null,
                initiatorAcceptKey: self::ACCEPT_KEY,
            )),
            '',
            HilosSignalConstants::HILOS_SESSION_REBIND,
        );
        $signedOut = $this->lastStateFrame();
        $this->assertNotNull($signedOut);
        $this->assertNotSame($rotated, $signedOut->sessionToken);
        $this->assertSame($sessionId, $signedOut->sessionId);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted(
            $sessionId,
            self::USER_ID,
            SecondFactorSettings::DEFAULT_TRUST_DAYS,
        ));
        $this->grant($signedOut->sessionToken);

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'The trusted browser is signed in at once');
    }

    /**
     * A shorter policy refuses an old long trust until the holder caps it; zero always refuses it.
     *
     * @throws HilosException When enrolment, lookup, or a trust write fails
     */
    public function testTrustPolicyAndSavedDeadline(): void
    {
        $this->enrol();
        $sessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $savedAt = time();
        $savedAtSql = date('Y-m-d H:i:s', $savedAt);
        $longUntil = date('Y-m-d H:i:s', $savedAt + 365 * TimeConstants::SECONDS_PER_DAY);
        $shortUntil = date('Y-m-d H:i:s', $savedAt + 7 * TimeConstants::SECONDS_PER_DAY);
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, self::USER_ID, $longUntil);

        $defaults = SecondFactorPolicy::defaults();
        $policy = new SecondFactorPolicy(
            $defaults->required,
            7,
            $defaults->backupCodes,
            $defaults->resetWaitDefaultDays,
            $defaults->resetWaitMinDays,
            $defaults->resetWaitMaxDays,
        );
        $this->assertSame(SecondFactorGate::VERIFY, SecondFactorGate::verdict($sessionId, self::USER_ID, $policy));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 0));

        Hilos::$db->secondFactorTrusts->actions->capLiveUntil($shortUntil, $savedAtSql);
        $this->assertSame(SecondFactorGate::PASS, SecondFactorGate::verdict($sessionId, self::USER_ID, $policy));
        Hilos::$db->secondFactorTrusts->actions->capLiveUntil(
            date('Y-m-d H:i:s', $savedAt + 30 * TimeConstants::SECONDS_PER_DAY),
            $savedAtSql,
        );
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 7));

        Hilos::$db->secondFactorTrusts->actions->deleteAll();
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 365));
    }

    /**
     * An already signed-out browser still loses trust when other browsers are revoked.
     *
     * @throws HilosException When a session or trust write fails
     */
    public function testTrustRevocationIncludesSignedOutBrowsers(): void
    {
        $currentSessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $otherToken = 'cc00000000000000000000000000cc94';
        self::seedSession($otherToken, null, self::CREATED_AT, null);
        $otherSessionId = Hilos::$db->sessions->findByToken($otherToken)->id;
        $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
        Hilos::$db->secondFactorTrusts->actions->trust($currentSessionId, self::USER_ID, $until);
        Hilos::$db->secondFactorTrusts->actions->trust($otherSessionId, self::USER_ID, $until);

        Hilos::$db->secondFactorTrusts->actions->deleteForUserExceptSession(self::USER_ID, $currentSessionId);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 30));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherSessionId, self::USER_ID, 30));

        Hilos::$db->secondFactorTrusts->actions->deleteForPair($currentSessionId, self::USER_ID);
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 30));
    }

    /**
     * The holder answers a shortened term only after the trust row is capped.
     *
     * @throws HilosException When enrolment, lookup, or a trust write fails
     */
    public function testTrustTermHandoverAnswersAfterTheWrite(): void
    {
        $sessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $savedAt = time();
        Hilos::$db->secondFactorTrusts->actions->trust(
            $sessionId,
            self::USER_ID,
            date('Y-m-d H:i:s', $savedAt + 365 * TimeConstants::SECONDS_PER_DAY),
        );
        $this->drain();
        $ask = new AuthSecondFactorTrustDaysApplySignalData(
            trustDays: 7,
            savedAt: $savedAt,
            replySignal: 'trust-term-test-done',
            acceptKey: self::ACCEPT_KEY,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::SETTING_UPDATE,
            successMessage: 'Trust term saved.',
        );

        $this->holder->onSignalAgent(
            new AgentSignalData($ask),
            'test',
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY,
        );

        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 7));
        $answer = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $answer = $signal->data->data;
            }
        }
        $this->assertNotNull($answer);
        $this->assertNull($answer->error);
        $this->assertSame(self::REQUEST_ID, $answer->requestId);
        $this->assertSame('Trust term saved.', $answer->successMessage);

        $this->holder->onSignalAgent(
            new AgentSignalData(new AuthSecondFactorTrustDaysApplySignalData(
                trustDays: 0,
                savedAt: $savedAt,
                replySignal: 'trust-term-test-done',
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::SETTING_UPDATE,
                successMessage: null,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY,
        );
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 365));
    }

    /**
     * A missing trust source is an internal refusal, but the pending admin action still gets an answer.
     *
     * @throws HilosException When the handover cannot be queued
     */
    public function testTrustTermHandoverAnswersAWiringRefusal(): void
    {
        $originalDb = Hilos::$db;
        Hilos::$db = new SecondFactorRefusingTrustDbContext();
        try {
            $this->holder->onSignalAgent(
                new AgentSignalData(new AuthSecondFactorTrustDaysApplySignalData(
                    trustDays: 0,
                    savedAt: time(),
                    replySignal: 'trust-term-test-done',
                    acceptKey: self::ACCEPT_KEY,
                    requestId: self::REQUEST_ID,
                    action: HilosSignalConstants::SETTING_UPDATE,
                    successMessage: null,
                )),
                'test',
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY,
            );
        } finally {
            Hilos::$db = $originalDb;
        }

        $answers = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $answers[] = $signal->data->data;
            }
        }
        $this->assertCount(1, $answers);
        $this->assertNotNull($answers[0]->error);
        $this->assertSame('DbCollectionNotReadableException', $answers[0]->errorType);
    }

    /**
     * A new password saved by mail does not sign in past the factor, and holds its sentence.
     *
     * The other browser lost its trust to the person's agent, which took it away before it wrote the
     * password; the holder takes none (HIL-1407).
     *
     * @throws HilosException When the frame fails
     */
    public function testARecoveredPasswordWaitsOnTheCodeStep(): void
    {
        $this->enrol();
        $currentSessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $otherToken = 'dd00000000000000000000000000dd94';
        self::seedSession($otherToken, null, self::CREATED_AT, null);
        $otherSessionId = Hilos::$db->sessions->findByToken($otherToken)->id;
        Hilos::$db->secondFactorTrusts->actions->trust(
            $currentSessionId,
            self::USER_ID,
            date('Y-m-d H:i:s', time() + 365 * TimeConstants::SECONDS_PER_DAY),
        );
        Hilos::$db->secondFactorTrusts->actions->trust(
            $otherSessionId,
            self::USER_ID,
            date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY),
        );

        $this->recoverPassword($currentSessionId);
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new AuthPasswordChangedSignalData(
                self::USER_ID,
                self::SESSION_TOKEN,
                self::ACCEPT_KEY,
                'ada@example.test',
                self::REQUEST_ID,
                HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
        );

        $session = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN);
        $this->assertNull($session?->userId, 'Nobody is signed in');
        $this->assertSame(self::USER_ID, $session?->pendingSecondFactorUserId);
        $this->assertSame(SessionAck::PASSWORD_CHANGED, $session?->pendingSecondFactorAck);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 365));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherSessionId, self::USER_ID, 30));
    }

    /**
     * Recovery that signs in at once retains this browser's trust, and even a signed-out other loses
     * its own - taken away by the person's agent in the write of the new password (HIL-1407).
     *
     * @throws HilosException When a session or trust write fails
     */
    public function testRecoveredPasswordThatSignsInRevokesOtherTrusts(): void
    {
        $currentSessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $otherToken = 'ee00000000000000000000000000ee94';
        self::seedSession($otherToken, null, self::CREATED_AT, null);
        $otherSessionId = Hilos::$db->sessions->findByToken($otherToken)->id;
        $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
        Hilos::$db->secondFactorTrusts->actions->trust($currentSessionId, self::USER_ID, $until);
        Hilos::$db->secondFactorTrusts->actions->trust($otherSessionId, self::USER_ID, $until);

        $this->recoverPassword($currentSessionId);
        $this->holder->onSignalAgent(
            new AgentSignalData(new AuthPasswordChangedSignalData(
                self::USER_ID,
                self::SESSION_TOKEN,
                self::ACCEPT_KEY,
                'ada@example.test',
                self::REQUEST_ID,
                HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
        );

        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 30));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherSessionId, self::USER_ID, 30));
    }

    /**
     * An administrator's requirement enrols a person on the way in, and the codes screen lets them through.
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testARequiredSecondFactorIsEnrolledOnTheWayIn(): void
    {
        Hilos::$setting = new SettingsAccessor(SecondFactorRequiredTestCatalog::class);
        $this->grant();
        $this->assertSame(
            SecondFactorPendingMode::SETUP,
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorMode,
        );
        $this->drain();

        $start = $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START,
            $this->emptyDto(HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START),
        );
        $this->assertInstanceOf(AuthFlowOutcome::class, $start);
        $secret = (string)$start->secondFactor?->secret;
        $code = Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time()));

        $confirm = AuthFlowOutcome::fromArray((array)$this->runTracked(
            $this->library,
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM,
            new SecondFactorSetupConfirmActionDTO($code, 'Phone'),
        ));
        $this->assertSame(AuthFlowStep::SECOND_FACTOR_CODES, $confirm->step);
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, (array)$confirm->secondFactor?->backupCodes);
        $this->forwardToHolder();
        $this->assertSame(
            SecondFactorPendingMode::SETUP_DONE,
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorMode,
        );

        $this->library->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH,
            $this->emptyDto(HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH),
        );
        $this->forwardToHolder();
        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);
    }

    /**
     * Enrols a confirmed authenticator holding the RFC test secret.
     *
     * @throws HilosException When a write fails
     */
    private function enrol(): void
    {
        Hilos::$db->secondFactors->actions
            ->startEnrolment(self::USER_ID, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
    }

    /**
     * @return string The code of the enrolled authenticator right now
     */
    private function currentCode(): string
    {
        return Totp::codeAt(self::SECRET_BYTES, Totp::stepAt(time()));
    }

    /**
     * Has the person's agent write a recovered password, the way the users library asks it to: the
     * trust of every browser of the person but this one goes first (HIL-1407).
     *
     * @param int $keepSessionId Session row of the browser that recovers
     * @throws HilosException When the password row or the agent's write fails
     */
    private function recoverPassword(int $keepSessionId): void
    {
        $password = Hilos::$db->identities->createPasswordIdentity(self::USER_ID, 'ada@example.test', 'old-password-1407');
        $this->asPerson(self::USER_ID, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(new UserPasswordResetSignalData(
                userId: self::USER_ID,
                identityId: (int)$password->id,
                passwordHash: ObjectIdentity::hashPassword('new-password-1407'),
                email: 'ada@example.test',
                keepSessionId: $keepSessionId,
                replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
                successMessage: null,
            )),
            'test',
            HilosSignalConstants::HILOS_USER_PASSWORD_RESET,
        ));
        $answer = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE) {
                $answer = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            }
        }
        $this->assertInstanceOf(UserPasswordResetDoneSignalData::class, $answer);
        $this->assertNull($answer->error);
    }

    /**
     * Hands the holder a password sign-in of the person.
     *
     * @param string $sessionToken Session the sign-in arrived on
     * @throws HilosException When the grant fails
     */
    private function grant(string $sessionToken = self::SESSION_TOKEN): void
    {
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: $sessionToken,
                userId: self::USER_ID,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_LOGIN,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
    }

    /**
     * Puts the browser back on an anonymous session waiting on the code step.
     *
     * @throws HilosException When the seed or the grant fails
     */
    private function reseedAndHold(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $this->grant();
        $this->drain();
    }

    /**
     * Submits a code to the library, which has the person's agent check it.
     *
     * @param string $code Code as typed
     * @param bool $backupCode Whether it is a backup code
     * @param bool $trustDevice Whether to trust the browser
     * @throws ValidationException When the library or the person's agent refuses the code
     * @throws HilosException When the command fails
     */
    private function confirm(string $code, bool $backupCode = false, bool $trustDevice = false): void
    {
        $this->runTracked(
            $this->library,
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR,
            new ConfirmSecondFactorActionDTO($code, $backupCode, $trustDevice),
        );
    }

    /**
     * @param string $action Action whose payload has no fields
     * @return ActionPayloadDTO The empty payload
     */
    private function emptyDto(string $action): ActionPayloadDTO
    {
        $class = AbstractUsersLibraryAgent::AGENT_ACTIONS[$action];

        return $class::fromArray([]);
    }

    /**
     * Hands every frame the library queued for the holder across, dropping the rest.
     *
     * @throws HilosException When the holder fails on a frame
     */
    private function forwardToHolder(): void
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($signal->data instanceof AgentSignalData && isset(AbstractSessionsLibraryAgent::AGENT_SIGNALS[$name])) {
                $frames[] = [$name, $signal->data];
            }
        }
        foreach ($frames as [$name, $data]) {
            $this->holder->onSignalAgent($data, 'test', $name);
        }
    }

    /**
     * Drops every queued signal.
     */
    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // dropped
        }
    }

    /**
     * @return list<SessionStateSignalData> The session state frames queued since the last drain
     */
    private function stateFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof SessionStateSignalData) {
                $frames[] = $payload->data;
            }
        }

        return $frames;
    }

    /**
     * @return ?SessionStateSignalData The last session state frame queued since the last drain
     */
    private function lastStateFrame(): ?SessionStateSignalData
    {
        $frames = $this->stateFrames();

        return $frames === [] ? null : $frames[count($frames) - 1];
    }
}

/**
 * The second-factor catalog with the requirement raised to everybody.
 */
final class SecondFactorRequiredTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Catalog requiring a second factor of everybody
     */
    public static function getCatalog(): array
    {
        $catalog = SecondFactorSettingsCatalog::getCatalog();
        $catalog[SecondFactorSettings::REQUIRED_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]
            = SecondFactorSettings::REQUIRED_EVERYONE;

        return $catalog;
    }
}

/** A mounted context that refuses the holder's trust read, like missing source interest does. */
final class SecondFactorRefusingTrustDbContext extends HilosDbContext
{
    /**
     * @param string $name Collection the holder asks for
     * @return mixed No value: this fixture refuses the read
     * @throws DbCollectionNotReadableException Always, as the source is unavailable
     */
    public function __get(string $name): mixed
    {
        throw new DbCollectionNotReadableException("Collection {$name} is not readable in this process");
    }
}

/**
 * Runtime with the sign-in feature and the two tabs of the browser under test.
 */
final class SecondFactorTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SecondFactorTestConnections::init();
        $connections->add(SecondFactorTestConnection::create('accept-sf', null, 'bb00000000000000000000000000bb94'));
        $connections->add(SecondFactorTestConnection::create('accept-sf-sibling', null, 'bb00000000000000000000000000bb94'));
        $this->_stateCollections[SecondFactorTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Sessions library of the fixture project.
 */
final class SecondFactorTestHolder extends AbstractSessionsLibraryAgent
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
final class SecondFactorTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Name the new account would be created with
     * @param ?string $themePick Guest theme choice, or null when not chosen
     * @return int Never returns
     * @throws LogicException Always: these cases sign existing people in
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
final class SecondFactorTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'secondFactorTestConnections';

    public const string STATE_CLASS = SecondFactorTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SecondFactorTestConnection extends HilosSessionConnection
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
