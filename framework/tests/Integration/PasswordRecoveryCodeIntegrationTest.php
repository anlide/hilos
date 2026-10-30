<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Recovery\PasswordRecoveryService;
use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\DTO\UserPasswordResetDoneSignalData;
use Hilos\Users\DTO\UserPasswordResetSignalData;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The two-step spend a password recovery is built on (HIL-416).
 *
 * Recovery proves the code on one screen and saves the password on the next, so the
 * verification layer had to grow a check that does not spend ({@see VerificationService::matchCode()})
 * and a spend that does not ask ({@see VerificationService::consumeActive()}). What
 * is only true of the pair against a real table is pinned here: that matching leaves
 * the code where it was, that spending it is single-use so the second saver gets
 * nothing, and - the part worth a table rather than a mock - that not spending is not
 * the same as not counting, because the attempt ceiling still voids the challenge.
 *
 * The challenge is seeded through the object collection rather than issued, because
 * an issued code is only ever mailed and a test cannot know it.
 */
final class PasswordRecoveryCodeIntegrationTest extends FrameworkIntegrationTestCase
{
    use PersonAgentFrames;

    /** @var list<string> Framework tables this case needs */
    private const array TABLES = [
        'hilos_user',
        'hilos_user_merge',
        'hilos_identity',
        'hilos_user_verification',
        'hilos_second_factor_trust',
    ];

    private const string CODE = '424242';

    private const string WRONG_CODE = '000000';

    private const int TTL_SECONDS = 900;

    /** Attempts one code gets in this case, small enough to exhaust in a short loop. */
    private const int MAX_ATTEMPTS = 2;

    private const int PASSWORD_CHANGED_AT = 1700000000123;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array VERIFICATION_KNOBS = [
        'HILOS_VERIFICATION_MAX_ATTEMPTS',
        'HILOS_VERIFICATION_TTL_SEC',
    ];

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

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

        $db = new RecoveryCodeTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();

        putenv(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS->name . '=' . self::MAX_ATTEMPTS);
        putenv(EnvConstants::HILOS_VERIFICATION_TTL_SEC->name . '=' . self::TTL_SECONDS);
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach (self::VERIFICATION_KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /** A matched recovery code advances the moment only when the person's owner writes the new password. */
    public function testCompletingRecoveryRecordsTheNewPasswordMoment(): void
    {
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Recovery person')");
        $userId = Database::lastInsertId();
        $email = $this->uniqueEmail();
        $passwordId = Hilos::$db->identities->createPasswordIdentity($userId, $email, 'old-recovery-secret', verified: true)->id;
        self::assertNotNull($passwordId);
        Database::sqlRun('UPDATE `hilos_identity` SET `password_changed_at` = ? WHERE `id` = ?', [self::PASSWORD_CHANGED_AT, $passwordId]);
        Hilos::$db->forgetLoadedDataForTest();
        $this->seedCode($email);
        $recovery = new PasswordRecoveryService();

        self::assertTrue($recovery->acceptCode($email, self::CODE));
        self::assertSame(self::PASSWORD_CHANGED_AT, Hilos::$db->identities[$passwordId]->passwordChangedAt);
        $password = $recovery->complete($email, 'new-recovery-secret');
        self::assertNotNull($password);
        self::assertSame($passwordId, $password->id);
        self::assertSame(self::PASSWORD_CHANGED_AT, Hilos::$db->identities[$passwordId]->passwordChangedAt);
        self::assertTrue(Hilos::$db->identities[$passwordId]->verifyPassword('old-recovery-secret'));
        $before = TimeHelper::nowMs();
        $this->asPerson($userId, static fn ($agent) => $agent->onSignalAgent(new AgentSignalData(data: new UserPasswordResetSignalData(
            userId: $userId,
            identityId: $passwordId,
            passwordHash: Hilos::$db->identities->hashPassword('new-recovery-secret'),
            email: $email,
            keepSessionId: 0,
            replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE,
            acceptKey: 'recovery-browser',
            requestId: 'recovery-password-moment',
            action: HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            successMessage: null,
        )), '', HilosSignalConstants::HILOS_USER_PASSWORD_RESET));

        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof UserPasswordResetDoneSignalData) {
                $answers[] = $signal->data->data;
            }
        }
        self::assertCount(1, $answers);
        self::assertNull($answers[0]->error);
        $row = Database::sql('SELECT `secret`, `password_changed_at` FROM `hilos_identity` WHERE `id` = ?', [$passwordId])->rows()[0];
        self::assertTrue(password_verify('new-recovery-secret', $row['secret']));
        self::assertGreaterThan(self::PASSWORD_CHANGED_AT, (int)$row['password_changed_at']);
        self::assertGreaterThanOrEqual($before, (int)$row['password_changed_at']);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), (int)$row['password_changed_at']);
        self::assertSame((int)$row['password_changed_at'], Hilos::$db->identities[$passwordId]->passwordChangedAt);
        self::assertNull($recovery->complete($email, 'another-recovery-secret'));
        self::assertSame($row, Database::sql(
            'SELECT `secret`, `password_changed_at` FROM `hilos_identity` WHERE `id` = ?',
            [$passwordId],
        )->rows()[0]);
    }

    /**
     * A proven code is still there for the step that spends it.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testAMatchedCodeIsNotSpentByMatchingIt(): void
    {
        $email = $this->uniqueEmail();
        $this->seedCode($email);
        $service = new VerificationService();

        self::assertTrue($service->matchCode(VerificationType::PASSWORD_RESET, $email, self::CODE));
        self::assertTrue(
            $service->consumeActive(VerificationType::PASSWORD_RESET, $email),
            'The proven code must survive into the step that saves the password',
        );
    }

    /**
     * Spending is single-use: the second save finds nothing left and says so.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testTheSpendIsSingleUseSoTheSecondSaverGetsNothing(): void
    {
        $email = $this->uniqueEmail();
        $this->seedCode($email);
        $service = new VerificationService();

        self::assertTrue($service->consumeActive(VerificationType::PASSWORD_RESET, $email));
        self::assertFalse(
            $service->consumeActive(VerificationType::PASSWORD_RESET, $email),
            'A spent recovery must not be completable twice',
        );
        self::assertFalse(
            $service->matchCode(VerificationType::PASSWORD_RESET, $email, self::CODE),
            'A spent code must stop proving anything',
        );
    }

    /**
     * Not spending the code is not the same as not counting the attempt.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testAWrongCodeCostsAnAttemptAndTheCeilingVoidsTheChallenge(): void
    {
        $email = $this->uniqueEmail();
        $this->seedCode($email);
        $service = new VerificationService();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            self::assertFalse($service->matchCode(VerificationType::PASSWORD_RESET, $email, self::WRONG_CODE));
        }

        self::assertFalse(
            $service->matchCode(VerificationType::PASSWORD_RESET, $email, self::CODE),
            'The ceiling must void the challenge on the non-spending path too',
        );
        self::assertFalse($service->consumeActive(VerificationType::PASSWORD_RESET, $email));
    }

    /**
     * The right code given on the last permitted attempt still buys the save.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testACorrectCodeDoesNotSpendTheCeilingThatGuardsWrongOnes(): void
    {
        $email = $this->uniqueEmail();
        $this->seedCode($email);
        $service = new VerificationService();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS - 1; $attempt++) {
            self::assertFalse($service->matchCode(VerificationType::PASSWORD_RESET, $email, self::WRONG_CODE));
        }

        self::assertTrue($service->matchCode(VerificationType::PASSWORD_RESET, $email, self::CODE));
        self::assertTrue(
            $service->consumeActive(VerificationType::PASSWORD_RESET, $email),
            'A right answer must not put the challenge over the ceiling meant for wrong ones',
        );
    }

    /**
     * An address nobody is recovering has nothing to prove and nothing to spend.
     *
     * @throws HilosException When a verification query fails
     */
    public function testAnAddressWithNoLiveCodeAnswersBothCallsWithFalse(): void
    {
        $email = $this->uniqueEmail();
        $service = new VerificationService();

        self::assertFalse($service->matchCode(VerificationType::PASSWORD_RESET, $email, self::CODE));
        self::assertFalse($service->consumeActive(VerificationType::PASSWORD_RESET, $email));
    }

    /**
     * Seeds a live challenge with a code this case knows.
     *
     * @param string $email Address being recovered
     * @throws HilosException When the challenge insert fails
     */
    private function seedCode(string $email): void
    {
        $this->verifications()->createChallenge(
            VerificationType::PASSWORD_RESET,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
    }

    /**
     * @return ObjectUserVerifications Verification persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * @return string Unique lowercase address for one case
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws HilosException When a stub statement fails
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
 *
 * The verification layer is framework-owned and reads one framework table, so the
 * smallest honest context for it is {@see HilosDbContext} with no project collections.
 */
final class RecoveryCodeTestDbContext extends HilosDbContext
{
}
