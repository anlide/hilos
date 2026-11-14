<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Generator;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorDisableApplySignalData;
use Hilos\Auth\Library\DTO\AuthSecondFactorTrustDaysApplySignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\ConfirmSecondFactorActionDTO;
use Hilos\Auth\Library\DTO\SecondFactorSetupConfirmActionDTO;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\SecondFactorGate;
use Hilos\Auth\SecondFactor\SecondFactorMessages;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\SecondFactor\Totp;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\DTO\UserBrowserTrustRenewDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Users\SecondFactorFate;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Log\AgentLogStream;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;

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
 * (HIL-1406). Turned off for the installation (HIL-1303), the factor lets every proof through,
 * and the session holder applies the switch to the waits, then waits on the trusts' owners to
 * forget them (HIL-1909): the restriction frames are carried to the people's agents and back. A
 * shorter trust term is brought home the same way: the holder cuts nothing and answers once the
 * owners have cut their trusts (HIL-1908); a case that needs the holder's schedule lives in
 * {@see SecondFactorTrustRestrictionSweepIntegrationTest}, whose project draws a sign-in surface.
 */
final class SecondFactorSignInIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int CREATED_AT = 1790240400000;

    private const string SESSION_TOKEN = 'bb00000000000000000000000000bb94';

    private const string ACCEPT_KEY = 'accept-sf';

    private const string SIBLING_KEY = 'accept-sf-sibling';

    private const string REQUEST_ID = 'request-sf';

    /** Agent signal the settings library would be answered under when the factor is turned off. */
    private const string TURNED_OFF_REPLY = 'second-factor-off-test-done';

    /** Agent signal the settings library would be answered under when a shorter trust term is saved. */
    private const string TRUST_TERM_REPLY = 'trust-term-test-done';

    /** Success sentence the gatekeeper hands along with a shorter trust term. */
    private const string TRUST_TERM_SAVED = 'Trust term saved.';

    private const int USER_ID = 77;

    /** The account {@see USER_ID} is folded into where a case needs it folded. */
    private const int SURVIVOR_ID = 78;

    private const int DAY_MS = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;

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
     * The code step counts down to the deadline the row stores, in the same milliseconds, on the
     * sign-in frame and on a reload: nothing multiplies it a second time.
     *
     * @throws HilosException When the grant or the handshake fails
     * @throws DatabaseException When reading the row fails
     */
    public function testTheCodeStepCarriesTheStoredDeadlineInMilliseconds(): void
    {
        $this->enrol();
        $ttl = Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int() * TimeConstants::MS_PER_SECOND;

        $before = TimeHelper::nowMs();
        $this->grant();
        $after = TimeHelper::nowMs();

        Database::sql('SELECT `pending_second_factor_until` FROM `hilos_session` WHERE `token` = ?', [self::SESSION_TOKEN]);
        $until = (int)Database::field('pending_second_factor_until');
        $this->assertGreaterThanOrEqual($before + $ttl, $until);
        $this->assertLessThanOrEqual($after + $ttl, $until);
        $frames = $this->stateFrames();
        $this->assertSame($until, $frames[1]->pendingAuthStep[HandshakeResponseSignalData::expiresAt] ?? null);

        $this->holder->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: self::ACCEPT_KEY,
                cookies: [],
                clientIp: null,
                sessionToken: self::SESSION_TOKEN,
            ),
            'test',
            'handshake',
        );

        $this->assertSame($until, $this->lastStateFrame()?->pendingAuthStep[HandshakeResponseSignalData::expiresAt] ?? null);
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
            $this->assertSame(self::USER_ID, Hilos::$db->userMerges[78]->survivorUserId);
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
     * A shorter policy refuses an old long trust until its owner cuts it to the saved restriction; a
     * longer one extends nothing, and zero takes the trust out (HIL-1908).
     *
     * @throws HilosException When enrolment, lookup, a trust write, the apply or a frame fails
     */
    public function testTrustPolicyAndSavedDeadline(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        Hilos::$db->secondFactorTrusts->actions->trust(
            $sessionId,
            self::USER_ID,
            TimeHelper::nowMs() + 365 * self::DAY_MS,
            TimeHelper::nowMs(),
        );
        $savedAt = TimeHelper::nowMs();

        $defaults = SecondFactorPolicy::defaults();
        $policy = new SecondFactorPolicy(
            $defaults->enabled,
            $defaults->forgetTrustsOnDisable,
            $defaults->required,
            7,
            $defaults->backupCodes,
            $defaults->resetWaitDefaultDays,
            $defaults->resetWaitMinDays,
            $defaults->resetWaitMaxDays,
        );
        $this->assertSame(SecondFactorGate::VERIFY, SecondFactorGate::verdict($sessionId, self::USER_ID, $policy));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 0));

        $this->bringHome($this->restrict($savedAt, 7));
        $this->assertSame(SecondFactorGate::PASS, SecondFactorGate::verdict($sessionId, self::USER_ID, $policy));
        $this->bringHome($this->restrict($savedAt, 30));
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 7));

        $this->bringHome($this->restrict(TimeHelper::nowMs(), 0));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, 365));
    }

    /**
     * A grant stores the trust deadline in milliseconds, a restriction saved at a moment that is not
     * a whole second cuts it to one that is not either, and a deadline already past is refused.
     */
    public function testGrantAndCapKeepAFractionalDeadlineAndRefuseAnExpiredTrust(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $beforeGrant = TimeHelper::nowMs();
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_LOGIN,
                secondFactorProven: true,
                trustDevice: true,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
        $afterGrant = TimeHelper::nowMs();

        $granted = self::trustRow($sessionId);
        $this->assertNotNull($granted);
        $grantedOffset = SecondFactorSettings::DEFAULT_TRUST_DAYS * self::DAY_MS;
        $this->assertGreaterThanOrEqual($beforeGrant + $grantedOffset, $granted[3]);
        $this->assertLessThanOrEqual($afterGrant + $grantedOffset, $granted[3]);

        // Saved after the grant, 123 milliseconds past a whole second.
        $savedAt = intdiv($granted[2], TimeConstants::MS_PER_SECOND) * TimeConstants::MS_PER_SECOND
            + TimeConstants::MS_PER_SECOND
            + 123;
        $this->bringHome($this->restrict($savedAt, 6));
        $cappedUntil = self::trustRow($sessionId)[3] ?? null;
        $this->assertSame($savedAt + 6 * self::DAY_MS, $cappedUntil);
        $this->assertSame(123, $cappedUntil % TimeConstants::MS_PER_SECOND);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted(
            $sessionId,
            self::USER_ID,
            SecondFactorSettings::DEFAULT_TRUST_DAYS,
        ));
        $this->assertSame(
            SecondFactorGate::PASS,
            SecondFactorGate::verdict($sessionId, self::USER_ID, SecondFactorPolicy::current()),
        );

        $expiredUntil = 1700000000123;
        Database::sqlRun(
            'UPDATE `hilos_second_factor_trust` SET `trusted_until` = ? WHERE `session_id` = ? AND `user_id` = ?',
            [$expiredUntil, $sessionId, self::USER_ID],
        );
        // The row was written past the ORM, so it is read through a cold context.
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->assertSame($expiredUntil, self::trustRow($sessionId)[3] ?? null);
        $this->assertSame(123, $expiredUntil % TimeConstants::MS_PER_SECOND);
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, SecondFactorSettings::TRUST_DAYS_MAX));
        $this->assertSame(
            SecondFactorGate::VERIFY,
            SecondFactorGate::verdict($sessionId, self::USER_ID, SecondFactorPolicy::current()),
        );
    }

    /**
     * A proof that asks to trust a browser whose pair stands leaves the row to the person's agent:
     * the holder writes nothing and asks once, with the proof as the grant and the policy's days
     * from it; the agent extends the pair, and the holder logs nothing of the answer (HIL-1907).
     *
     * @throws HilosException When enrolment, a frame or a read fails
     */
    public function testAProofOnAStandingPairAsksThePersonsAgentToRenewIt(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $this->trustSince($sessionId, TimeHelper::nowMs() - 10 * self::DAY_MS);
        $standing = self::trustRow($sessionId);
        $this->assertNotNull($standing);
        $this->drain();

        $before = TimeHelper::nowMs();
        $this->proveTrustingThisBrowser();
        $after = TimeHelper::nowMs();

        $this->assertSame($standing, self::trustRow($sessionId), 'The holder leaves a standing pair as it is');
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);
        $this->assertSame([self::USER_ID, $sessionId], [$asks[0]->userId, $asks[0]->sessionId]);
        $this->assertGreaterThanOrEqual($before, $asks[0]->grantedAt);
        $this->assertLessThanOrEqual($after, $asks[0]->grantedAt);
        $this->assertSame($asks[0]->grantedAt + SecondFactorSettings::DEFAULT_TRUST_DAYS * self::DAY_MS, $asks[0]->trustedUntil);

        $answer = null;
        $errors = $this->holderErrors(function () use ($asks, &$answer): void {
            $answer = $this->carryRenewal($asks[0]);
        });

        $this->assertTrue($answer?->renewed);
        $this->assertNull($answer?->error);
        $this->assertSame('', $errors);
        $this->assertSame([$standing[0], $standing[1], $asks[0]->grantedAt, $asks[0]->trustedUntil], self::trustRow($sessionId));
    }

    /**
     * A pair whose trust ran out is extended all the same, and the browser passes the gate again (HIL-1907).
     *
     * @throws HilosException When enrolment, a frame or a read fails
     */
    public function testAnExpiredPairIsRenewedAndPassesTheGate(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $this->trustSince($sessionId, TimeHelper::nowMs() - 40 * self::DAY_MS);
        $this->assertSame(SecondFactorGate::VERIFY, SecondFactorGate::verdict($sessionId, self::USER_ID, SecondFactorPolicy::current()));
        $this->drain();

        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);

        $this->assertTrue($this->carryRenewal($asks[0])->renewed);
        $this->assertSame(SecondFactorGate::PASS, SecondFactorGate::verdict($sessionId, self::USER_ID, SecondFactorPolicy::current()));
    }

    /**
     * A renewal that arrives after a later one changes nothing: the pair keeps the later grant,
     * and both are answered that it stands (HIL-1907).
     *
     * @throws HilosException When a frame or a read fails
     */
    public function testAnOlderRenewalChangesNothing(): void
    {
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $now = TimeHelper::nowMs();
        $this->trustSince($sessionId, $now - 10 * self::DAY_MS);
        $earlier = self::renewalAt($sessionId, $now - 2 * TimeConstants::MS_PER_SECOND);
        $later = self::renewalAt($sessionId, $now - TimeConstants::MS_PER_SECOND);

        $this->assertTrue($this->carryRenewal($later)->renewed);
        $this->assertTrue($this->carryRenewal($earlier)->renewed);

        $this->assertSame([$later->grantedAt, $later->trustedUntil], array_slice((array)self::trustRow($sessionId), 2));
    }

    /**
     * A restriction of 0 days saved after the pair's grant and before the proof, and not yet
     * applied, does not take out the pair the proof renews: the proof is the newer grant (HIL-1907).
     *
     * @throws HilosException When enrolment, the restriction, a frame or a read fails
     */
    public function testAFreshProofOutlivesAMissedRestrictionWhileThePairStands(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $now = TimeHelper::nowMs();
        $this->trustSince($sessionId, $now - 10 * self::DAY_MS);
        $this->restrict($now - self::DAY_MS, 0);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes(self::USER_ID));
        $this->drain();

        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);

        $this->assertTrue($this->carryRenewal($asks[0])->renewed);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, SecondFactorSettings::DEFAULT_TRUST_DAYS));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes(self::USER_ID));
    }

    /**
     * The same missed restriction applied by the person's agent before the renewal reaches it
     * leaves no pair to extend; the holder writes it again with the proof's grant and end, and
     * the new pair is not asked to be extended (HIL-1907).
     *
     * @throws HilosException When enrolment, the restriction, a frame or a read fails
     */
    public function testAFreshProofOutlivesAMissedRestrictionTheAgentAppliedFirst(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $now = TimeHelper::nowMs();
        $this->trustSince($sessionId, $now - 10 * self::DAY_MS);
        $standing = self::trustRow($sessionId);
        $this->assertNotNull($standing);
        $this->restrict($now - self::DAY_MS, 0);
        $this->drain();

        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);
        $this->asPerson(self::USER_ID, static fn () => Hilos::$db->secondFactorTrusts->actions->applySavedRestrictions(self::USER_ID));
        $this->assertNull(self::trustRow($sessionId));

        $this->assertFalse($this->carryRenewal($asks[0])->renewed);

        $written = self::trustRow($sessionId);
        $this->assertNotNull($written);
        $this->assertNotSame($standing[0], $written[0]);
        $this->assertSame([$asks[0]->grantedAt, $asks[0]->trustedUntil], array_slice($written, 2));
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, SecondFactorSettings::DEFAULT_TRUST_DAYS));
        $this->assertSame([], $this->renewalAsks());
    }

    /**
     * A restriction saved after the proof cuts the pair the proof renews, in the same transaction (HIL-1907).
     *
     * @throws HilosException When enrolment, the restriction, a frame or a read fails
     */
    public function testARestrictionSavedAfterTheProofCutsTheRenewedPair(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $this->trustSince($sessionId, TimeHelper::nowMs() - 10 * self::DAY_MS);
        $this->drain();
        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);
        $savedAt = $asks[0]->grantedAt + 1;
        $this->restrict($savedAt, 7);

        $this->assertTrue($this->carryRenewal($asks[0])->renewed);

        $this->assertSame([$asks[0]->grantedAt, $savedAt + 7 * self::DAY_MS], array_slice((array)self::trustRow($sessionId), 2));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->getObjectCollection()->owes(self::USER_ID));
    }

    /**
     * A pair taken away between the proof and the agent's turn is not written again. Four ways
     * take it: the browser signed out and lost its trust; the person switched the factor off with
     * every trust; the person changed the password on another browser without ending the other
     * sessions, and the agent took the trust of every browser but that one; or the policy gives no
     * more days, and the pair went while the browser stayed signed in, the factor confirmed and the
     * password unchanged. A recovery and a change that ends the other sessions take the trust
     * through the same write of the new password by the agent, which marks the moment of the
     * change, so the third way stands for them too. That is the ordinary end of a revoke, and the
     * holder logs nothing (HIL-1907).
     *
     * @param SecondFactorPairTakeaway $way How the pair goes between the proof and the agent's turn
     * @throws HilosException When enrolment, a frame or a read fails
     */
    #[DataProvider('waysThePairIsTakenAway')]
    public function testAPairTakenAwayAfterTheProofIsNotRecreated(SecondFactorPairTakeaway $way): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $otherToken = 'aa00000000000000000000000000aa94';
        if ($way === SecondFactorPairTakeaway::PasswordChangedElsewhere) {
            self::seedSession($otherToken, self::USER_ID, self::CREATED_AT, null);
            Hilos::$db->identities->createPasswordIdentity(self::USER_ID, 'ada@example.test', 'old-password-1907');
        }
        $this->trustSince($sessionId, TimeHelper::nowMs() - 10 * self::DAY_MS);
        $this->drain();
        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);

        switch ($way) {
            case SecondFactorPairTakeaway::SignedOut:
                $this->holder->onSignalAgent(
                    new AgentSignalData(data: new SessionRebindSignalData(
                        sessionToken: Hilos::$db->sessions[$sessionId]->token,
                        userId: null,
                        initiatorAcceptKey: self::ACCEPT_KEY,
                    )),
                    '',
                    HilosSignalConstants::HILOS_SESSION_REBIND,
                );
                $this->asPerson(self::USER_ID, static fn () => Hilos::$db->secondFactorTrusts->actions->deleteForPair($sessionId, self::USER_ID));
                break;
            case SecondFactorPairTakeaway::FactorSwitchedOff:
                $this->asPerson(self::USER_ID, static function (): void {
                    Hilos::$db->secondFactors->actions->deleteForUser(self::USER_ID);
                    Hilos::$db->secondFactorBackupCodes->actions->deleteForUser(self::USER_ID);
                    Hilos::$db->secondFactorTrusts->actions->deleteForUser(self::USER_ID);
                });
                break;
            case SecondFactorPairTakeaway::PasswordChangedElsewhere:
                $this->changePasswordOn((int)Hilos::$db->sessions->findByToken($otherToken)?->id);
                $this->assertSame(self::USER_ID, Hilos::$db->sessions[$sessionId]->userId, 'This browser stays signed in');
                break;
            case SecondFactorPairTakeaway::TrustDaysTurnedOff:
                Hilos::$setting = new SettingsAccessor(SecondFactorTrustTurnedOffTestCatalog::class);
                $this->asPerson(self::USER_ID, static fn () => Hilos::$db->secondFactorTrusts->actions->deleteForUser(self::USER_ID));
                break;
        }
        $this->assertNull(self::trustRow($sessionId));
        $answer = null;
        $errors = $this->holderErrors(function () use ($asks, &$answer): void {
            $answer = $this->carryRenewal($asks[0]);
        });

        $this->assertFalse($answer?->renewed);
        $this->assertNull(self::trustRow($sessionId));
        $this->assertSame('', $errors);
        $this->assertSame([], $this->renewalAsks());
    }

    /**
     * @return array<string, array{SecondFactorPairTakeaway}> How the pair goes between the proof and the agent's turn
     */
    public static function waysThePairIsTakenAway(): array
    {
        return [
            'signed out and its trust taken away' => [SecondFactorPairTakeaway::SignedOut],
            'the second factor switched off' => [SecondFactorPairTakeaway::FactorSwitchedOff],
            'the password changed on another browser' => [SecondFactorPairTakeaway::PasswordChangedElsewhere],
            'the policy gives no more days' => [SecondFactorPairTakeaway::TrustDaysTurnedOff],
        ];
    }

    /**
     * A proof after the pair was taken away writes a new pair at once, and asks the person's agent nothing (HIL-1907).
     *
     * @throws HilosException When enrolment, a frame or a read fails
     */
    public function testRevocationThenAFreshProofCreatesANewPair(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $this->trustSince($sessionId, TimeHelper::nowMs() - 10 * self::DAY_MS);
        $revoked = self::trustRow($sessionId);
        $this->assertNotNull($revoked);
        $this->asPerson(self::USER_ID, static fn () => Hilos::$db->secondFactorTrusts->actions->deleteForPair($sessionId, self::USER_ID));
        $this->drain();

        $before = TimeHelper::nowMs();
        $this->proveTrustingThisBrowser();
        $after = TimeHelper::nowMs();

        $this->assertSame([], $this->renewalAsks());
        $written = self::trustRow($sessionId);
        $this->assertNotNull($written);
        $this->assertNotSame($revoked[0], $written[0]);
        $this->assertGreaterThanOrEqual($before, $written[2]);
        $this->assertLessThanOrEqual($after, $written[2]);
    }

    /**
     * A renewal the person's agent refuses - the account was folded meanwhile - is logged by the
     * holder naming the person and no browser's session, and the pair stays as it was (HIL-1907).
     *
     * @throws HilosException When enrolment, a fixture write, a frame or a read fails
     */
    public function testARefusedRenewalIsLoggedWithoutTheSession(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $this->trustSince($sessionId, TimeHelper::nowMs() - 10 * self::DAY_MS);
        $standing = self::trustRow($sessionId);
        $this->drain();
        $this->proveTrustingThisBrowser();
        $asks = $this->renewalAsks();
        $this->assertCount(1, $asks);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Survivor')", [self::SURVIVOR_ID]);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [self::USER_ID, self::SURVIVOR_ID, TimeHelper::nowMs()],
        );

        $answer = null;
        $errors = $this->holderErrors(function () use ($asks, &$answer): void {
            $answer = $this->carryRenewal($asks[0]);
        });

        $this->assertNotNull($answer?->error);
        $this->assertStringContainsString('Browser trust renewal for #' . self::USER_ID . ' refused', $errors);
        $this->assertStringNotContainsStringIgnoringCase('session', $errors);
        $this->assertStringNotContainsString(Hilos::$db->sessions[$sessionId]->token, $errors);
        $this->assertSame($standing, self::trustRow($sessionId));
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
        $until = TimeHelper::nowMs() + 30 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        Hilos::$db->secondFactorTrusts->actions->trust($currentSessionId, self::USER_ID, $until, TimeHelper::nowMs());
        Hilos::$db->secondFactorTrusts->actions->trust($otherSessionId, self::USER_ID, $until, TimeHelper::nowMs());

        Hilos::$db->secondFactorTrusts->actions->deleteForUserExceptSession(self::USER_ID, $currentSessionId);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 30));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherSessionId, self::USER_ID, 30));

        Hilos::$db->secondFactorTrusts->actions->deleteForPair($currentSessionId, self::USER_ID);
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, self::USER_ID, 30));
    }

    /**
     * The holder writes nothing for a shorter term: it asks the owner to cut the trust to the
     * restriction the save recorded, and answers the write once the owner has; a term of none is
     * brought home the same way and takes the trust out (HIL-1908).
     *
     * @throws HilosException When a trust write, the apply or a frame fails
     */
    public function testTrustTermHandoverAnswersAfterTheOwnerCutsTheTrust(): void
    {
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $grantedAt = TimeHelper::nowMs();
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, self::USER_ID, $grantedAt + 365 * self::DAY_MS, $grantedAt);
        $savedAt = TimeHelper::nowMs();
        $restrictionId = $this->restrict($savedAt, 7);
        $this->drain();

        $this->applyTrustTerm($restrictionId);
        $asked = $this->queued();
        $frames = self::named($asked, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);

        $this->assertSame([self::USER_ID], self::askedPeople($frames));
        $this->assertSame([], self::trustTermAnswers($asked), 'The write waits for the owner');
        $this->assertSame($grantedAt + 365 * self::DAY_MS, self::trustRow($sessionId)[3] ?? null, 'The holder cuts nothing');

        $answers = self::trustTermAnswers($this->carryRestrictions($frames));

        $this->assertCount(1, $answers);
        $this->assertNull($answers[0]->error);
        $this->assertSame(self::REQUEST_ID, $answers[0]->requestId);
        $this->assertSame(self::TRUST_TERM_SAVED, $answers[0]->successMessage);
        $this->assertSame($savedAt + 7 * self::DAY_MS, self::trustRow($sessionId)[3] ?? null);

        $this->assertNull($this->bringHome($this->restrict(TimeHelper::nowMs(), 0))->error);
        $this->assertNull(self::trustRow($sessionId));
    }

    /**
     * Every owner of a trust the shorter term breaks is asked, the one whose agent sleeps as well,
     * and the write is answered only after the last of them (HIL-1908).
     *
     * @throws HilosException When a trust write, the apply or a frame fails
     */
    public function testTrustTermWaitsForEveryOwnerTheSleepingOneToo(): void
    {
        $sleeperId = 79;
        $sleeperToken = 'dd00000000000000000000000000dd94';
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Sleeper')", [$sleeperId]);
        self::seedSession($sleeperToken, null, self::CREATED_AT, null);
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $sleeperSessionId = (int)Hilos::$db->sessions->findByToken($sleeperToken)->id;
        $now = TimeHelper::nowMs();
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, self::USER_ID, $now + 30 * self::DAY_MS, $now);
        Hilos::$db->secondFactorTrusts->actions->trust($sleeperSessionId, $sleeperId, $now + 30 * self::DAY_MS, $now);
        $this->personAgent(self::USER_ID);
        $savedAt = TimeHelper::nowMs();
        $restrictionId = $this->restrict($savedAt, 7);
        $this->drain();

        $this->applyTrustTerm($restrictionId);
        $frames = self::named($this->queued(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);

        $this->assertSame([self::USER_ID, $sleeperId], self::askedPeople($frames));
        $this->assertSame([], self::trustTermAnswers($this->carryRestrictions([$frames[0]])), 'One owner is still to answer');
        $this->assertSame([$savedAt + 7 * self::DAY_MS, $now + 30 * self::DAY_MS], self::deadlinesOf(self::USER_ID, $sleeperId));

        $answers = self::trustTermAnswers($this->carryRestrictions([$frames[1]]));

        $this->assertCount(1, $answers);
        $this->assertNull($answers[0]->error);
        $this->assertSame([$savedAt + 7 * self::DAY_MS, $savedAt + 7 * self::DAY_MS], self::deadlinesOf(self::USER_ID, $sleeperId));
    }

    /**
     * An owner who refuses leaves the write answered with the refusal that counts the trusts still
     * standing; the other owner's trust is cut, and the term stays saved (HIL-1908).
     *
     * @throws HilosException When a trust write, the apply or a frame fails
     */
    public function testAnOwnerWhoDoesNotCutLeavesTheTrustTermAnsweredWithARefusal(): void
    {
        $refusingId = 79;
        $refusingToken = 'cd00000000000000000000000000cd94';
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Owner')", [$refusingId]);
        self::seedSession($refusingToken, null, self::CREATED_AT, null);
        $now = TimeHelper::nowMs();
        foreach ([self::USER_ID => self::SESSION_TOKEN, $refusingId => $refusingToken] as $userId => $token) {
            $sessionId = (int)Hilos::$db->sessions->findByToken($token)->id;
            Hilos::$db->secondFactorTrusts->actions->trust($sessionId, $userId, $now + 30 * self::DAY_MS, $now);
        }
        $savedAt = TimeHelper::nowMs();
        $restrictionId = $this->restrict($savedAt, 7);
        $this->drain();
        $this->applyTrustTerm($restrictionId);
        $frames = self::named($this->queued(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);
        $this->assertSame([self::USER_ID, $refusingId], self::askedPeople($frames));
        $this->assertSame([], self::trustTermAnswers($this->carryRestrictions([$frames[0]])));

        $this->holder->onSignalAgent(
            new AgentSignalData(new UserSecondFactorTrustRestrictDoneSignalData(
                self::restrictAsk($frames[1]),
                'The trusts could not be changed',
                DatabaseException::class,
                'Trust storage failure',
            )),
            'test',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE,
        );
        $answers = self::trustTermAnswers($this->queued());

        $this->assertCount(1, $answers);
        $this->assertSame(
            'The trust term is saved, but 1 trusted browsers are not cut to it yet.'
                . ' Sign-in holds them to it already; save the same term again to finish.',
            $answers[0]->error,
        );
        $this->assertSame([$savedAt + 7 * self::DAY_MS, $now + 30 * self::DAY_MS], self::deadlinesOf(self::USER_ID, $refusingId));
        $this->assertSame($restrictionId, Hilos::$db->secondFactorTrustRestrictions->getObjectCollection()->latestId());
    }

    /**
     * The same restriction handed over again, everything owed being cut already, asks nobody and is
     * answered before the frame is done with (HIL-1908).
     *
     * @throws HilosException When a trust write, the apply or a frame fails
     */
    public function testTheSameTrustTermAgainAsksNobodyAndAnswersAtOnce(): void
    {
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $now = TimeHelper::nowMs();
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, self::USER_ID, $now + 30 * self::DAY_MS, $now);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 7);
        $this->assertNull($this->bringHome($restrictionId)->error);
        $this->drain();

        $this->applyTrustTerm($restrictionId);
        $again = $this->queued();

        $this->assertSame([], self::named($again, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT));
        $this->assertCount(1, self::trustTermAnswers($again));
        $this->assertNull(self::trustTermAnswers($again)[0]->error);
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
            TimeHelper::nowMs() + 365 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            TimeHelper::nowMs(),
        );
        Hilos::$db->secondFactorTrusts->actions->trust(
            $otherSessionId,
            self::USER_ID,
            TimeHelper::nowMs() + 30 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            TimeHelper::nowMs(),
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
        $until = TimeHelper::nowMs() + 30 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        Hilos::$db->secondFactorTrusts->actions->trust($currentSessionId, self::USER_ID, $until, TimeHelper::nowMs());
        Hilos::$db->secondFactorTrusts->actions->trust($otherSessionId, self::USER_ID, $until, TimeHelper::nowMs());

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

        $confirm = $this->setupConfirm($this->setupStart());
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
     * Pressed again on the way in, the enrolment screen leaves only the latest enrolment: the
     * person's agent drops the first before the second is started, so the code of the first QR is
     * refused and the code of the second is taken (HIL-1903).
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testARepeatedStartOnTheWayInLeavesOnlyTheLatestEnrolment(): void
    {
        Hilos::$setting = new SettingsAccessor(SecondFactorRequiredTestCatalog::class);
        $this->grant();
        $this->drain();

        $first = $this->setupStart();
        $second = $this->setupStart();
        $this->assertNotSame($first, $second);
        $unfinished = array_values(array_filter(
            Hilos::$db->secondFactors->listByUser(self::USER_ID),
            static fn (SecondFactor $factor): bool => $factor->confirmedAt === null,
        ));
        $this->assertCount(1, $unfinished, 'The second start dropped the first enrolment');
        $this->assertSame($second, $unfinished[0]->readSecret());

        try {
            $this->setupConfirm($first);
            $this->fail('The code of the first QR must be refused');
        } catch (ValidationException $refusal) {
            $this->assertSame(SecondFactorMessages::INVALID_CODE, $refusal->getMessage());
        }

        $confirm = $this->setupConfirm($second);
        $this->assertSame(AuthFlowStep::SECOND_FACTOR_CODES, $confirm->step);
        $this->assertCount(1, Hilos::$db->secondFactors->confirmedOf(self::USER_ID));
        $this->assertNull(Hilos::$db->secondFactors->unconfirmedOf(self::USER_ID));
    }

    /**
     * The first app connected on the way in replaces a set of backup codes left from before: the
     * person's agent drops the set, and the codes screen shows a new one (HIL-1904).
     *
     * @throws HilosException When a frame or a command fails
     */
    public function testTheFirstAppOnTheWayInReplacesALeftoverSet(): void
    {
        Hilos::$setting = new SettingsAccessor(SecondFactorRequiredTestCatalog::class);
        $this->grant();
        $this->drain();
        Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, ['leftoverab']);

        $confirm = $this->setupConfirm($this->setupStart());

        $this->assertSame(AuthFlowStep::SECOND_FACTOR_CODES, $confirm->step);
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, (array)$confirm->secondFactor?->backupCodes);
        $this->assertNull(Hilos::$db->secondFactorBackupCodes->findUnused(self::USER_ID, 'leftoverab'), 'The leftover set was dropped');
        $this->assertCount(SecondFactorSettings::DEFAULT_BACKUP_CODES, Hilos::$db->secondFactorBackupCodes->listByUser(self::USER_ID));
    }

    /**
     * Turned off for the installation, the factor asks a person with an app no code (HIL-1303).
     *
     * @throws HilosException When enrolment or the grant fails
     */
    public function testATurnedOffSecondFactorSignsInWithoutTheCode(): void
    {
        $this->enrol();
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);

        $this->grant();

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'The password alone signs the person in');
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN), 'The token rotated on the sign-in');
        $this->assertCount(0, Hilos::$db->sessions->findAwaitingSecondFactor(), 'Nothing waits on a second factor');
    }

    /**
     * A new password saved by mail signs in at once while the factor is turned off (HIL-1303).
     *
     * @throws HilosException When enrolment, the agent's write or the frame fails
     */
    public function testARecoveredPasswordSignsInAtOnceWhileTurnedOff(): void
    {
        $this->enrol();
        $this->recoverPassword((int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id);
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);

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

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'Recovery lets the person in');
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN), 'The token rotated on the sign-in');
    }

    /**
     * Turning the factor off lets every wait go back to the start of the sign-in, the code step and
     * the enrolment alike, and the holder answers the write after it (HIL-1303).
     *
     * @throws HilosException When a grant, the apply or a lookup fails
     */
    public function testTurningOffLetsEveryWaitGo(): void
    {
        $this->enrol();
        $this->grant();
        $setupToken = 'cc00000000000000000000000000cc94';
        $setupUserId = 78;
        self::seedSession($setupToken, null, self::CREATED_AT, null);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Newcomer')", [$setupUserId]);
        Hilos::$setting = new SettingsAccessor(SecondFactorRequiredTestCatalog::class);
        $this->holder->onSignalAgent(
            new AgentSignalData(new AuthSessionGrantSignalData(
                sessionToken: $setupToken,
                userId: $setupUserId,
                acceptKey: 'accept-sf-newcomer',
                requestId: 'request-sf-newcomer',
                action: HilosSignalConstants::HILOS_LOGIN,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
        $this->assertSame(
            SecondFactorPendingMode::VERIFY,
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorMode,
        );
        $this->assertSame(SecondFactorPendingMode::SETUP, Hilos::$db->sessions->findByToken($setupToken)?->pendingSecondFactorMode);
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();

        $this->applyTurnedOff($restrictionId);

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData) {
                $signals[] = $signal->data->data;
            }
        }
        $states = array_values(array_filter($signals, static fn ($data): bool => $data instanceof SessionStateSignalData));
        $this->assertCount(1, $states, 'Both tabs of the waiting browser are told in one frame, nobody is answered');
        $this->assertSame([self::ACCEPT_KEY, self::SIBLING_KEY], $states[0]->acceptKeys);
        $this->assertNull($states[0]->userId);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $states[0]->pendingAuthStep[HandshakeResponseSignalData::step] ?? null);
        $this->assertNull($states[0]->pendingAuthStep[HandshakeResponseSignalData::code] ?? null, 'No reason is given');
        $answer = $signals[count($signals) - 1];
        $this->assertInstanceOf(HandoverAnswerSignalData::class, $answer, 'The write is answered last');
        $this->assertNull($answer->error);
        $this->assertSame(self::REQUEST_ID, $answer->requestId);
        $this->assertNull(Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorUserId);
        $this->assertNull(Hilos::$db->sessions->findByToken($setupToken)?->pendingSecondFactorUserId);
        $this->assertNull(Hilos::$db->sessions->findByToken($setupToken)?->userId, 'Nobody is signed in by the release');
    }

    /**
     * Turning the factor off forgets the trusts only when the setting says so; a trust kept lets the
     * browser skip the code step until its end once the factor is back on (HIL-1303). Forgetting is
     * the owner's: until the person's agent answers, the trusts stand and the write is not answered,
     * and once it is forgotten the browser trusted before is asked for the code again (HIL-1909).
     *
     * @throws HilosException When enrolment, a trust write, the apply, a frame or the grant fails
     */
    public function testTurningOffForgetsTrustsOnlyWhenTheSettingSays(): void
    {
        $this->enrol();
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        $otherToken = 'ff00000000000000000000000000ff94';
        self::seedSession($otherToken, null, self::CREATED_AT, null);
        $otherSessionId = (int)Hilos::$db->sessions->findByToken($otherToken)->id;
        $until = TimeHelper::nowMs() + 7 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, self::USER_ID, $until, TimeHelper::nowMs());
        Hilos::$db->secondFactorTrusts->actions->trust($otherSessionId, self::USER_ID, $until, TimeHelper::nowMs());
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);

        $this->applyTurnedOff(null);
        $kept = $this->queued();

        $this->assertSame([], self::named($kept, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT), 'Nobody is asked to forget');
        $this->assertCount(1, self::turnedOffAnswers($kept));
        $this->assertNull(self::turnedOffAnswers($kept)[0]->error);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, self::USER_ID, SecondFactorSettings::DEFAULT_TRUST_DAYS));
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        $this->grant();
        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'The kept trust skips the code step once it is back on');

        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->applyTurnedOff($restrictionId);
        $asked = $this->queued();
        $frames = self::named($asked, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);

        $this->assertSame([self::USER_ID], self::askedPeople($frames), 'The owner is asked once for both browsers');
        $this->assertSame([], self::turnedOffAnswers($asked), 'The write waits for the owner');
        $this->assertNotNull(self::trustRow($sessionId), 'The trusts stand until their owner forgets them');
        $this->assertNotNull(self::trustRow($otherSessionId));

        $answers = self::turnedOffAnswers($this->carryRestrictions($frames));

        $this->assertCount(1, $answers);
        $this->assertNull($answers[0]->error);
        $this->assertSame(self::REQUEST_ID, $answers[0]->requestId);
        $this->assertNull(self::trustRow($sessionId));
        $this->assertNull(self::trustRow($otherSessionId));
        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        $this->grant($otherToken);
        $this->assertSame(
            SecondFactorPendingMode::VERIFY,
            Hilos::$db->sessions->findByToken($otherToken)?->pendingSecondFactorMode,
            'The browser trusted before asks for the code again once the factor is back on',
        );
    }

    /**
     * Every owner of a trust is asked, the one whose agent sleeps as well, and the write is answered
     * only after the last of them; what the people enrolled stays theirs (HIL-1909).
     *
     * @throws HilosException When enrolment, a trust write, the apply or a frame fails
     */
    public function testTurningOffWaitsForEveryOwnerTheSleepingOneToo(): void
    {
        $sleeperId = 79;
        $sleeperToken = 'dd00000000000000000000000000dd94';
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Sleeper')", [$sleeperId]);
        $this->enrol();
        Hilos::$db->secondFactors->actions
            ->startEnrolment($sleeperId, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
        Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, ['abcdefghjk']);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($sleeperId, ['kjhgfedcba']);
        $sessionId = (int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id;
        self::seedSession($sleeperToken, null, self::CREATED_AT, null);
        $sleeperSessionId = (int)Hilos::$db->sessions->findByToken($sleeperToken)->id;
        $now = TimeHelper::nowMs();
        $this->trustSince($sessionId, $now);
        Hilos::$db->secondFactorTrusts->actions->trust($sleeperSessionId, $sleeperId, $now + self::DAY_MS, $now);
        $this->personAgent(self::USER_ID);
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();

        $this->applyTurnedOff($restrictionId);
        $frames = self::named($this->queued(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);

        $this->assertSame([self::USER_ID, $sleeperId], self::askedPeople($frames));
        $this->assertSame([], self::turnedOffAnswers($this->carryRestrictions([$frames[0]])), 'One owner is still to answer');
        $this->assertNull(self::trustRow($sessionId));
        $this->assertSame(1, self::trustsOf($sleeperId), 'The sleeping owner has not forgotten yet');

        $answers = self::turnedOffAnswers($this->carryRestrictions([$frames[1]]));

        $this->assertCount(1, $answers);
        $this->assertNull($answers[0]->error);
        $this->assertSame(0, self::trustsOf(self::USER_ID, $sleeperId));
        foreach ([self::USER_ID, $sleeperId] as $userId) {
            $this->assertCount(1, Hilos::$db->secondFactors->listByUser($userId), 'The app stays');
            $this->assertCount(1, Hilos::$db->secondFactorBackupCodes->listByUser($userId), 'The backup codes stay');
        }
    }

    /**
     * The waits on a code are let go before any owner is asked to forget a trust (HIL-1909).
     *
     * @throws HilosException When enrolment, a trust write, the grant or the apply fails
     */
    public function testTurningOffLetsTheWaitsGoBeforeAskingTheOwners(): void
    {
        $this->enrol();
        $otherToken = 'ee00000000000000000000000000ee94';
        self::seedSession($otherToken, null, self::CREATED_AT, null);
        $this->trustSince((int)Hilos::$db->sessions->findByToken($otherToken)->id, TimeHelper::nowMs());
        $this->grant();
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();

        $this->applyTurnedOff($restrictionId);

        $released = null;
        $asked = null;
        foreach ($this->queued() as $index => $signal) {
            if ($released === null && $signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                $released = $index;
            }
            if ($asked === null && $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT) {
                $asked = $index;
            }
        }
        $this->assertNotNull($released, 'The waiting browser is let go');
        $this->assertNotNull($asked, 'The owner is asked');
        $this->assertLessThan($asked, $released);
    }

    /**
     * The same switch-off handed over again asks nobody, everything owed being forgotten already,
     * and is answered at once (HIL-1909).
     *
     * @throws HilosException When enrolment, a trust write, the apply or a frame fails
     */
    public function testTurningOffAgainAsksNobodyAndAnswersAtOnce(): void
    {
        $this->enrol();
        $this->trustSince((int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id, TimeHelper::nowMs());
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();
        $this->applyTurnedOff($restrictionId);
        $first = self::turnedOffAnswers(
            $this->carryRestrictions(self::named($this->queued(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT)),
        );
        $this->assertCount(1, $first);

        $this->applyTurnedOff($restrictionId);
        $again = $this->queued();

        $this->assertSame([], self::named($again, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT));
        $this->assertCount(1, self::turnedOffAnswers($again));
        $this->assertNull(self::turnedOffAnswers($again)[0]->error);
    }

    /**
     * An owner who refuses leaves the trusts for later: the write is answered with the refusal that
     * says so, the others' trusts are forgotten, and the factor stays off (HIL-1909). An owner whose
     * agent is gone ends the same way; the holder hands that news to the sweep only where the project
     * draws a sign-in surface, which this fixture does not, and
     * {@see SecondFactorTrustRestrictionSweepIntegrationTest} drives it there.
     *
     * @throws HilosException When enrolment, a trust write, the apply, a frame or the grant fails
     */
    public function testAnOwnerWhoDoesNotForgetLeavesTheSwitchOffAnsweredWithARefusal(): void
    {
        $refusingId = 79;
        $refusingToken = 'cd00000000000000000000000000cd94';
        $this->enrol();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Owner')", [$refusingId]);
        self::seedSession($refusingToken, null, self::CREATED_AT, null);
        $trusted = [self::USER_ID => self::SESSION_TOKEN, $refusingId => $refusingToken];
        $now = TimeHelper::nowMs();
        foreach ($trusted as $userId => $token) {
            $sessionId = (int)Hilos::$db->sessions->findByToken($token)->id;
            Hilos::$db->secondFactorTrusts->actions->trust($sessionId, $userId, $now + self::DAY_MS, $now);
        }
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();
        $this->applyTurnedOff($restrictionId);
        $frames = self::named($this->queued(), HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT);
        $this->assertSame([self::USER_ID, $refusingId], self::askedPeople($frames));
        $this->assertSame([], self::turnedOffAnswers($this->carryRestrictions([$frames[0]])));

        $this->holder->onSignalAgent(
            new AgentSignalData(new UserSecondFactorTrustRestrictDoneSignalData(
                self::restrictAsk($frames[1]),
                'The trusts could not be changed',
                DatabaseException::class,
                'Trust storage failure',
            )),
            'test',
            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE,
        );
        $answers = self::turnedOffAnswers($this->queued());

        $this->assertCount(1, $answers);
        $this->assertSame(
            'Two-step verification is turned off, but some trusted browsers are not forgotten yet. They will be forgotten automatically.',
            $answers[0]->error,
        );
        $this->assertSame(0, self::trustsOf(self::USER_ID), 'The owner who answered forgot');
        $this->assertSame(1, self::trustsOf($refusingId), 'The refusing owner\'s trust is left for the next pass');
        $this->assertSame($restrictionId, Hilos::$db->secondFactorTrustRestrictions->getObjectCollection()->latestId());
        $this->grant();
        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'The factor stays off');
    }

    /**
     * A switch-off whose waits cannot be let go is refused at once, and no owner is asked (HIL-1909).
     *
     * @throws HilosException When enrolment, a trust write or the handover fails
     */
    public function testTurningOffThatCannotLetTheWaitsGoIsRefusedWithoutAskingTheOwners(): void
    {
        $this->enrol();
        $this->trustSince((int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id, TimeHelper::nowMs());
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $restrictionId = $this->restrict(TimeHelper::nowMs(), 0);
        $this->drain();
        $originalDb = Hilos::$db;
        Hilos::$db = new SecondFactorRefusingTrustDbContext();
        try {
            $this->applyTurnedOff($restrictionId);
        } finally {
            Hilos::$db = $originalDb;
        }
        $queued = $this->queued();

        $this->assertSame([], self::named($queued, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT));
        $answers = self::turnedOffAnswers($queued);
        $this->assertCount(1, $answers);
        $this->assertNotNull($answers[0]->error);
        $this->assertNotNull(self::trustRow((int)Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->id));
    }

    /**
     * Turned back on, the factor asks the same app again at the next sign-in (HIL-1303).
     *
     * @throws HilosException When enrolment, a grant or the code fails
     */
    public function testTurningBackOnAsksTheSameAppAgain(): void
    {
        $this->enrol();
        Hilos::$setting = new SettingsAccessor(SecondFactorTurnedOffTestCatalog::class);
        $this->grant();
        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId);

        Hilos::$setting = new SettingsAccessor(SecondFactorSettingsCatalog::class);
        $this->reseedAndHold();
        $this->assertSame(
            SecondFactorPendingMode::VERIFY,
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->pendingSecondFactorMode,
        );

        $this->confirm($this->currentCode());
        $this->forwardToHolder();

        $this->assertSame(self::USER_ID, $this->lastStateFrame()?->userId, 'The same app lets the person in');
    }

    /**
     * Hands the holder the settings library's ask to apply the factor turned off.
     *
     * @param ?int $restrictionId Restriction the switch-off recorded, or null when the trusts are kept
     * @throws HilosException When the holder fails on the frame
     */
    private function applyTurnedOff(?int $restrictionId): void
    {
        $this->holder->onSignalAgent(
            new AgentSignalData(new AuthSecondFactorDisableApplySignalData(
                restrictionId: $restrictionId,
                replySignal: self::TURNED_OFF_REPLY,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::SECURITY_2FA_SWITCH_SET,
                successMessage: null,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY,
        );
    }

    /**
     * Hands the holder the settings library's ask to bring a shorter trust term home (HIL-1908).
     *
     * @param int $restrictionId Restriction the save recorded
     * @throws HilosException When the holder fails on the frame
     */
    private function applyTrustTerm(int $restrictionId): void
    {
        $this->holder->onSignalAgent(
            new AgentSignalData(new AuthSecondFactorTrustDaysApplySignalData(
                restrictionId: $restrictionId,
                replySignal: self::TRUST_TERM_REPLY,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::SETTING_UPDATE,
                successMessage: self::TRUST_TERM_SAVED,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY,
        );
    }

    /**
     * Hands the holder a shorter trust term, carries every frame its sweep sends to the owners and
     * their answers back, and takes the holder's one answer (HIL-1908).
     *
     * @param int $restrictionId Restriction the save recorded
     * @return HandoverAnswerSignalData The holder's answer to the write
     * @throws HilosException When the holder or an owner fails on a frame
     */
    private function bringHome(int $restrictionId): HandoverAnswerSignalData
    {
        $this->drain();
        $this->applyTrustTerm($restrictionId);
        $asked = $this->queued();
        $answers = [
            ...self::trustTermAnswers($asked),
            ...self::trustTermAnswers(
                $this->carryRestrictions(self::named($asked, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT)),
            ),
        ];
        $this->assertCount(1, $answers, 'The holder answers the write once');

        return $answers[0];
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
     * Has the person's agent write a password changed on another browser without ending the other
     * sessions, the way the users library asks it to: the trust of every browser of the person but
     * that one goes first (HIL-1907).
     *
     * @param int $keepSessionId Session row of the browser the password is changed on
     * @throws HilosException When the frame cannot be queued, or the agent fails on it
     */
    private function changePasswordOn(int $keepSessionId): void
    {
        $this->library->sendToAgent(HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE, new UserPasswordChangeSignalData(
            userId: self::USER_ID,
            identityId: (int)Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->id,
            passwordHash: ObjectIdentity::hashPassword('new-password-1907'),
            signOutOthers: false,
            flowOpen: false,
            keepSessionId: $keepSessionId,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
            acceptKey: 'accept-sf-other',
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
            successMessage: null,
        ));
        $sent = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($sent);
        $this->deliverToPerson($sent);
        $answer = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE) {
                $answer = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            }
        }
        $this->assertInstanceOf(UserPasswordChangeDoneSignalData::class, $answer);
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
     * Opens the enrolment on the way in the way the screen does; the person's agent drops the
     * unfinished one first, and the start is answered after it.
     *
     * @return string The base32 secret the screen was answered with
     * @throws ValidationException When the library or the person's agent refuses the start
     * @throws HilosException When the command fails
     */
    private function setupStart(): string
    {
        $start = AuthFlowOutcome::fromArray((array)$this->runTracked(
            $this->library,
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START,
            $this->emptyDto(HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START),
        ));

        return (string)$start->secondFactor?->secret;
    }

    /**
     * Confirms the enrolment on the way in with the current code of an app.
     *
     * @param string $secret Base32 secret the app was given
     * @return AuthFlowOutcome The screen the enrolment moves to
     * @throws ValidationException When the library or the person's agent refuses the code
     * @throws HilosException When the command fails
     */
    private function setupConfirm(string $secret): AuthFlowOutcome
    {
        return AuthFlowOutcome::fromArray((array)$this->runTracked(
            $this->library,
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM,
            new SecondFactorSetupConfirmActionDTO(Totp::codeAt((string)Base32::decode($secret), Totp::stepAt(time())), 'Phone'),
        ));
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
     * Hands the holder a sign-in proven by the second factor with the browser's trust asked for,
     * the way the users library forwards it.
     *
     * @throws HilosException When the grant fails
     */
    private function proveTrustingThisBrowser(): void
    {
        $this->holder->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::ACCEPT_KEY,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_LOGIN,
                secondFactorProven: true,
                trustDevice: true,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
    }

    /**
     * Trusts the browser for the person as granted at a moment, for the policy's days from it.
     *
     * @param int $sessionId Session row of the browser
     * @param int $grantedAt Moment the trust was granted (epoch milliseconds)
     * @throws HilosException When the trust cannot be written
     */
    private function trustSince(int $sessionId, int $grantedAt): void
    {
        Hilos::$db->secondFactorTrusts->actions->trust(
            $sessionId,
            self::USER_ID,
            $grantedAt + SecondFactorSettings::DEFAULT_TRUST_DAYS * self::DAY_MS,
            $grantedAt,
        );
    }

    /**
     * Saves a trust restriction the way the settings library does, in its own frame (HIL-1900).
     *
     * @param int $savedAt Moment the restriction was saved (epoch milliseconds)
     * @param int $termDays Days a trust granted by then may still live; 0 takes it out
     * @return int Id of the stored restriction
     * @throws HilosException When the restriction cannot be written
     */
    private function restrict(int $savedAt, int $termDays): int
    {
        $settings = new SettingsLibraryAgent();
        OwnershipDeclaration::claimDb($settings::class, $settings->getId());
        try {
            return ExecutionContext::run(
                new ExecutionFrame(agentId: $settings->getId()),
                static fn (): int => Hilos::$db->secondFactorTrustRestrictions->actions->record($savedAt, $termDays),
            );
        } finally {
            TruthSourceRegistry::unregisterAgent($settings->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($settings->getId()));
        }
    }

    /**
     * @param int $sessionId Session row of the browser
     * @param int $grantedAt Moment the second factor was confirmed (epoch milliseconds)
     * @return UserBrowserTrustRenewSignalData A renewal of the person's trust in the browser from that moment for the policy's days
     */
    private static function renewalAt(int $sessionId, int $grantedAt): UserBrowserTrustRenewSignalData
    {
        return new UserBrowserTrustRenewSignalData(
            self::USER_ID,
            $sessionId,
            $grantedAt + SecondFactorSettings::DEFAULT_TRUST_DAYS * self::DAY_MS,
            $grantedAt,
            HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE,
        );
    }

    /**
     * @return list<UserBrowserTrustRenewSignalData> The renewals the holder asked the person's agent for since the last drain;
     *     every other signal is dropped
     */
    private function renewalAsks(): array
    {
        $asks = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW) {
                $this->assertInstanceOf(AgentSignalData::class, $signal->data);
                $this->assertInstanceOf(UserBrowserTrustRenewSignalData::class, $signal->data->data);
                $asks[] = $signal->data->data;
            }
        }

        return $asks;
    }

    /**
     * Sends one renewal the way the holder does, delivers it to the person's agent, and hands the
     * agent's one answer back to the holder.
     *
     * @param UserBrowserTrustRenewSignalData $ask The renewal
     * @return UserBrowserTrustRenewDoneSignalData The agent's answer, as the holder took it
     * @throws HilosException When the frame cannot be queued, or the agent or the holder fails on it
     */
    private function carryRenewal(UserBrowserTrustRenewSignalData $ask): UserBrowserTrustRenewDoneSignalData
    {
        $this->drain();
        $this->holder->sendToAgent(HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW, $ask);
        $sent = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($sent);
        $this->deliverToPerson($sent);

        $answers = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE) {
                $answers[] = $signal->data;
            }
        }
        $this->assertCount(1, $answers, 'The agent answers the renewal once');
        $this->assertInstanceOf(AgentSignalData::class, $answers[0]);
        $answer = $answers[0]->data;
        $this->assertInstanceOf(UserBrowserTrustRenewDoneSignalData::class, $answer);
        $this->assertSame($ask->toArray(), $answer->request->toArray());
        $this->holder->onSignalAgent($answers[0], 'test', HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE);

        return $answer;
    }

    /**
     * Runs a step with the log going to a file of its own, and reads what the holder wrote to its
     * error stream meanwhile: an agent's line goes to a stream file beside the log file.
     *
     * @param Closure(): void $step What to run
     * @return string What the holder wrote to its error stream during the step
     * @throws HilosException When the step fails
     */
    private function holderErrors(Closure $step): string
    {
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-second-factor-sign-in-log');
        $stream = AgentLogStream::pathFor(dirname($logFile), $this->holder->getId(), true);
        file_put_contents($stream, '');
        Logger::setLogFile($logFile);
        try {
            $step();

            return (string)file_get_contents($stream);
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
            unlink($stream);
        }
    }

    /**
     * @param int $sessionId Session row of the browser
     * @return ?list<int> Id, first written, last granted and end of the person's stored trust in the browser, or null when it has none
     * @throws DatabaseException When the read fails
     */
    private static function trustRow(int $sessionId): ?array
    {
        $row = Database::sql(
            'SELECT `id`, `created_at`, `granted_at`, `trusted_until` FROM `hilos_second_factor_trust` WHERE `session_id` = ? AND `user_id` = ?',
            [$sessionId, self::USER_ID],
        )->firstRow();
        if ($row === null) {
            return null;
        }

        return [(int)$row['id'], (int)$row['created_at'], (int)$row['granted_at'], (int)$row['trusted_until']];
    }

    /**
     * Delivers restriction frames to the people's agents and hands each agent's answer back to the holder.
     *
     * @param list<SignalDTO> $frames Restriction frames the holder's sweep sent
     * @return list<SignalDTO> Everything else queued meanwhile, the holder's own frames after the answers included
     * @throws HilosException When an agent or the holder fails on a frame
     */
    private function carryRestrictions(array $frames): array
    {
        foreach ($frames as $frame) {
            $this->deliverToPerson($frame);
        }
        $answers = [];
        $others = [];
        foreach ($this->queued() as $signal) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE) {
                $this->assertInstanceOf(AgentSignalData::class, $signal->data);
                $this->assertInstanceOf(UserSecondFactorTrustRestrictDoneSignalData::class, $signal->data->data);
                $this->assertNull($signal->data->data->error);
                $answers[] = $signal->data;
            } else {
                $others[] = $signal;
            }
        }
        $this->assertCount(count($frames), $answers, 'Every owner answers once');
        foreach ($answers as $answer) {
            $this->holder->onSignalAgent($answer, 'test', HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE);
        }

        return [...$others, ...$this->queued()];
    }

    /**
     * @return list<SignalDTO> Every signal queued since the last look, in the order it was queued
     */
    private function queued(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Queued signals
     * @param string $name Signal name to keep
     * @return list<SignalDTO> The signals under that name, in order
     */
    private static function named(array $signals, string $name): array
    {
        return array_values(array_filter($signals, static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name));
    }

    /**
     * @param list<SignalDTO> $signals Queued signals
     * @return list<HandoverAnswerSignalData> The holder's answers to the switch-off, in order
     */
    private static function turnedOffAnswers(array $signals): array
    {
        $answers = [];
        foreach (self::named($signals, self::TURNED_OFF_REPLY) as $signal) {
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(HandoverAnswerSignalData::class, $signal->data->data);
            $answers[] = $signal->data->data;
        }

        return $answers;
    }

    /**
     * @param list<SignalDTO> $signals Queued signals
     * @return list<HandoverAnswerSignalData> The holder's answers to the trust term, in order
     */
    private static function trustTermAnswers(array $signals): array
    {
        $answers = [];
        foreach (self::named($signals, self::TRUST_TERM_REPLY) as $signal) {
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(HandoverAnswerSignalData::class, $signal->data->data);
            $answers[] = $signal->data->data;
        }

        return $answers;
    }

    /**
     * @param SignalDTO $frame A restriction frame the holder's sweep sent
     * @return UserSecondFactorTrustRestrictSignalData What the frame asks
     */
    private static function restrictAsk(SignalDTO $frame): UserSecondFactorTrustRestrictSignalData
    {
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        self::assertInstanceOf(UserSecondFactorTrustRestrictSignalData::class, $frame->data->data);

        return $frame->data->data;
    }

    /**
     * @param list<SignalDTO> $frames Restriction frames the holder's sweep sent
     * @return list<int> People asked, in the order of the frames
     */
    private static function askedPeople(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): int => self::restrictAsk($frame)->userId, $frames);
    }

    /**
     * @param int ...$userIds People whose trusts are counted
     * @return int Stored trusts of those people, in any browser
     * @throws DatabaseException When the read fails
     */
    private static function trustsOf(int ...$userIds): int
    {
        $marks = implode(', ', array_fill(0, count($userIds), '?'));
        Database::sql("SELECT COUNT(*) AS `n` FROM `hilos_second_factor_trust` WHERE `user_id` IN ({$marks})", $userIds);

        return (int)Database::field('n');
    }

    /**
     * @param int ...$userIds People whose trusts are read
     * @return list<int> End of every stored trust of those people, in the order of the people named
     * @throws DatabaseException When a read fails
     */
    private static function deadlinesOf(int ...$userIds): array
    {
        $deadlines = [];
        foreach ($userIds as $userId) {
            foreach (Database::sql(
                'SELECT `trusted_until` FROM `hilos_second_factor_trust` WHERE `user_id` = ? ORDER BY `id`',
                [$userId],
            )->rows() as $row) {
                $deadlines[] = (int)$row['trusted_until'];
            }
        }

        return $deadlines;
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

/**
 * The second-factor catalog with the second factor turned off for the installation (HIL-1303).
 */
final class SecondFactorTurnedOffTestCatalog implements CatalogProviderInterface
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
 * The second-factor catalog with the trust of a browser turned off: the policy gives no days (HIL-1907).
 */
final class SecondFactorTrustTurnedOffTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Catalog giving a trusted browser no days
     */
    public static function getCatalog(): array
    {
        $catalog = SecondFactorSettingsCatalog::getCatalog();
        $catalog[SecondFactorSettings::TRUST_DAYS_KEY][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] = 0;

        return $catalog;
    }
}

/**
 * How a pair goes between the proof and the person's agent's turn (HIL-1907).
 */
enum SecondFactorPairTakeaway
{
    /** The browser signed out, and its trust was taken away. */
    case SignedOut;

    /** The person switched the second factor off with every trust. */
    case FactorSwitchedOff;

    /** The person changed the password on another browser without ending the other sessions. */
    case PasswordChangedElsewhere;

    /** The policy gives no more days, and the pair was taken away. */
    case TrustDaysTurnedOff;
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
    /**
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Incoming field changes
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}
