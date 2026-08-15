<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

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
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_user_verification'];

    private const string CODE = '424242';

    private const string WRONG_CODE = '000000';

    private const int TTL_SECONDS = 900;

    /** Attempts one code gets in this case, small enough to exhaust in a short loop. */
    private const int MAX_ATTEMPTS = 2;

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
        foreach (self::VERIFICATION_KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
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
        foreach (self::TABLES as $table) {
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
