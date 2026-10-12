<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\DTO\AuthCodeMintSignalData;
use Hilos\Auth\Code\DTO\AuthCodeMintedSignalData;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldExpiredSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationHoldReleaseSignalData;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogStub;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Random\RandomException;

/** Codes and holds are written under the users library's own execution frame (HIL-1411). */
final class SignInTablesOneWriterIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string PHONE = '+12025550141';
    private const string SESSION = 'ab000000000000000000000000001411';
    private const string OTHER_SESSION = 'cd000000000000000000000000001411';

    private SignInTablesTestUsersLibrary $users;
    private AuthCodeAgent $codes;
    private SignInTablesTestSessionsLibrary $sessions;
    private ?SignalRouter $previousRouter = null;
    private string $previousAppClass;
    private string|false $previousCooldown;

    /** @throws HilosException When the schema or the library claims cannot be prepared */
    protected function setUp(): void
    {
        parent::setUp();
        self::verificationTable(down: true);
        self::verificationTable(down: false);
        $this->previousAppClass = Hilos::appClass();
        SignInTablesTestHilos::initBrowser();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousCooldown = getenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name);
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name . '=600');
        $this->users = new SignInTablesTestUsersLibrary();
        $this->codes = new AuthCodeAgent();
        $this->sessions = new SignInTablesTestSessionsLibrary();
        foreach ([$this->users, $this->codes, $this->sessions] as $agent) {
            OwnershipDeclaration::claimDb($agent::class, $agent->getId());
        }
    }

    /** @throws HilosException When the stub tables cannot be removed */
    protected function tearDown(): void
    {
        foreach ([$this->users, $this->codes, $this->sessions] as $agent) {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
        }
        $this->previousAppClass::initBrowser();
        Hilos::$sr = $this->previousRouter;
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name
            . ($this->previousCooldown === false ? '' : '=' . $this->previousCooldown));
        self::verificationTable(down: true);
        parent::tearDown();
    }

    /**
     * @throws HilosException When a table read or an unexpected issue failure occurs
     * @throws RandomException When the code generator fails before the write guard
     */
    public function testTheCodeAgentIssuingACodeItselfIsRefused(): void
    {
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->codes->getId()),
                static fn () => new VerificationService()->issueForChannel(VerificationType::SMS_LOGIN, self::PHONE, null, 'sms'),
            );
            self::fail('The code agent must ask the users library to issue a code');
        } catch (WriteNotAllowedException | CreateNotAllowedException | DbCollectionNotReadableException $refusal) {
            self::assertStringContainsString(HilosDbContext::verifications, $refusal->getMessage());
        }

        self::assertSame(0, self::codeCount());
    }

    /** @throws HilosException When a table read or an unexpected hold failure occurs */
    public function testTheCodeAgentTakingAHoldItselfIsRefused(): void
    {
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->codes->getId()),
                static fn () => new RegistrationReservationService()->hold(IdentityType::SMS, self::SESSION, self::PHONE),
            );
            self::fail('The code agent must ask the users library to hold an identifier');
        } catch (WriteNotAllowedException | CreateNotAllowedException | DbCollectionNotReadableException $refusal) {
            self::assertStringContainsString(HilosDbContext::registrationReservations, $refusal->getMessage());
        }

        self::assertNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
    }

    /** @throws HilosException When a fixture, table read or unexpected release failure occurs */
    public function testTheSessionsHolderReleasingAHoldItselfIsRefused(): void
    {
        $this->mint();

        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $this->sessions->getId()),
                static fn () => new RegistrationReservationService()->release(self::SESSION),
            );
            self::fail('The sessions holder must ask the users library to release a hold');
        } catch (WriteNotAllowedException | CreateNotAllowedException | DbCollectionNotReadableException $refusal) {
            self::assertStringContainsString(HilosDbContext::registrationReservations, $refusal->getMessage());
        }

        self::assertSame(self::PHONE, new RegistrationReservationService()->findActiveForSession(self::SESSION)?->identifier);
    }

    /** @throws HilosException When a mint or a table read fails */
    public function testMintForAFreeNumberHoldsThisSessionAndIssuesItsCode(): void
    {
        $minted = $this->mint();

        self::assertNotNull($minted->code);
        self::assertNull($minted->reason);
        self::assertTrue($minted->registration);
        self::assertSame('probe-handle', $minted->probeToken);
        self::assertNotNull($minted->resendAt);
        self::assertSame(
            new VerificationService()->activeExpiresAt(VerificationType::SMS_LOGIN, self::PHONE),
            $minted->expiresAt,
        );
        self::assertSame(self::PHONE, new RegistrationReservationService()->findActiveForSession(self::SESSION)?->identifier);
        self::assertTrue(new VerificationService()->verifyCode(VerificationType::SMS_LOGIN, self::PHONE, $minted->code));
    }

    /** @throws HilosException When a fixture, mint or read fails */
    public function testAnExistingAccountNeedsNoHoldOrRegistrationConsent(): void
    {
        self::seedIdentity(1411, IdentityType::SMS, self::PHONE);
        $minted = $this->mint(accepted: null);

        self::assertNotNull($minted->code);
        self::assertFalse($minted->registration);
        self::assertNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
    }

    /** @throws HilosException When a mint or read fails */
    public function testCooldownCarriesTheEarlierCodesExpiryAndTheJoiningSessionsHold(): void
    {
        $first = $this->mint();
        $held = $this->mint(session: self::OTHER_SESSION);

        self::assertNull($held->code);
        self::assertSame(HilosCodeSendAttempt::REASON_RATE_LIMITED, $held->reason);
        self::assertSame($first->expiresAt, $held->expiresAt);
        self::assertNotNull($held->resendAt);
        self::assertTrue($held->registration);
        self::assertNotNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
        self::assertNotNull(new RegistrationReservationService()->findActiveForSession(self::OTHER_SESSION));
        self::assertSame(1, self::codeCount());
    }

    /** @throws HilosException When a mint or read fails */
    public function testMissingConsentLeavesNeitherACodeNorAHold(): void
    {
        $minted = $this->mint(accepted: null);

        self::assertNull($minted->code);
        self::assertSame(AuthFlowOutcome::CODE_CONSENT_REQUIRED, $minted->reason);
        self::assertFalse($minted->registration);
        self::assertNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
        self::assertSame(0, self::codeCount());
    }

    /** @throws HilosException When a fixture or sweep fails */
    public function testTheFirstLibraryTickExpiresOnlyTheOverdueSessionOnAnAddress(): void
    {
        $this->mint();
        $this->mint(session: self::OTHER_SESSION);
        Database::sqlRun(
            'UPDATE `hilos_registration_reservation` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 SECOND) '
            . 'WHERE `session_token` = ?',
            [self::SESSION],
        );
        Hilos::$db->getObjectCollection(HilosDbContext::registrationReservations)->clearInMemory();

        ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), function (): void {
            $this->users->onStart();
            $this->users->onTick();
        });

        $expired = $this->take(HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED);
        self::assertInstanceOf(AuthRegistrationHoldExpiredSignalData::class, $expired);
        self::assertSame(self::SESSION, $expired->sessionToken);
        self::assertSame(self::PHONE, $expired->identifier);
        self::assertNull($this->take(HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED));
        self::assertNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
        self::assertNotNull(new RegistrationReservationService()->findActiveForSession(self::OTHER_SESSION));
        self::assertCount(1, array_filter(
            $this->users->schedules(),
            static fn (CronRule $rule): bool => $rule->name === 'hilos_sweep_registration_reservations',
        ));
    }

    /** @throws HilosException When a fixture or release fails */
    public function testHoldReleaseNamesOnlyTheForgottenSession(): void
    {
        $this->mint();
        $this->mint(session: self::OTHER_SESSION);
        $this->deliver(
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE,
            new AuthRegistrationHoldReleaseSignalData(self::SESSION),
        );

        self::assertNull(new RegistrationReservationService()->findActiveForSession(self::SESSION));
        self::assertNotNull(new RegistrationReservationService()->findActiveForSession(self::OTHER_SESSION));
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotInstanceOf(AgentSignalData::class, $signal->data, 'A hold release requires no reply');
        }
    }

    /** @throws HilosException When the library starts */
    public function testAProjectWithoutAuthArmsNoReservationSweep(): void
    {
        Hilos::initBrowser();
        $this->users->onStart();
        self::assertSame([], array_values(array_filter(
            $this->users->schedules(),
            static fn (CronRule $rule): bool => $rule->name === 'hilos_sweep_registration_reservations',
        )));
    }

    /**
     * @param string $session Browser requesting the code
     * @param ?array<string, string> $accepted Consent boundary map
     * @return AuthCodeMintedSignalData Library's self-contained order
     * @throws HilosException When the frame or write fails
     */
    private function mint(string $session = self::SESSION, ?array $accepted = ['terms' => '2026-09-17']): AuthCodeMintedSignalData
    {
        $this->deliver(HilosSignalConstants::HILOS_AUTH_CODE_MINT, new AuthCodeMintSignalData(
            new AuthCodeSendSignalData('accept', $session, self::PHONE, 'sms', VerificationType::SMS_LOGIN, 'ticket', $accepted),
            'probe-handle',
        ));
        $minted = $this->take(HilosSignalConstants::HILOS_AUTH_CODE_MINTED);
        self::assertInstanceOf(AuthCodeMintedSignalData::class, $minted);

        return $minted;
    }

    /**
     * @param string $name Frame name
     * @param SignalDataInterface $payload Typed library request
     * @throws HilosException When the frame or write fails
     */
    private function deliver(string $name, SignalDataInterface $payload): void
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->users->getId()),
            fn () => $this->users->onSignalAgent(new AgentSignalData($payload), '', $name),
        );
    }

    /**
     * @param string $name Frame to take from the queue
     * @return ?SignalDataInterface Payload sent by the users library, or null if absent
     */
    private function take(string $name): ?SignalDataInterface
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                self::assertSame($this->users->getId(), $signal->signalSource->getType());
                self::assertInstanceOf(AgentSignalData::class, $signal->data);

                return $signal->data->data;
            }
        }

        return null;
    }

    /**
     * @return int Actual challenge rows for this number
     * @throws HilosException When the query fails
     */
    private static function codeCount(): int
    {
        Database::sql('SELECT COUNT(*) AS n FROM `hilos_user_verification` WHERE `identifier` = ?', [self::PHONE]);

        return (int)Database::row()['n'];
    }

    /**
     * @param bool $down Whether to remove the verification table
     * @throws HilosException When the stub statement fails
     */
    private static function verificationTable(bool $down): void
    {
        $stub = $down ? 'create_hilos_user_verification_down.sql' : 'create_hilos_user_verification.sql';
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $stub));
    }
}

/** Exposes the production schedule declaration without replacing the sweep. */
final class SignInTablesTestUsersLibrary extends AbstractUsersLibraryAgent
{
    /** @return list<CronRule> Schedules registered by the users library */
    public function schedules(): array
    {
        return $this->cronRules();
    }
}

/** A sign-in holder reads registration holds and inherits only its production write claims. */
final class SignInTablesTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    /** @var list<string> The sign-in feature's holds beside the framework's unconditional reads */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::registrationReservations,
    ];
}

/** Registration is enabled and backed by a published consent catalog. */
abstract class SignInTablesTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    protected const ?string LEGAL_CATALOG = LegalCatalogStub::class;
}
