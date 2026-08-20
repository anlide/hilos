<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Verification\VerificationRejectReason;
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
use Hilos\Tests\Unit\DaemonManagerLostSignalLogTest;
use Hilos\Utils\Logger;

/**
 * What a consume leaves in the log, and what it must never leave (HIL-607).
 *
 * The bug this comes from was diagnosed by hand, from the table, because a
 * magic-link click that failed wrote nothing anywhere: the daemon log, the error
 * log and the agent logs were all empty, so "the click never arrived" and "the
 * backend turned it down" looked identical. Both outcomes now write one line.
 *
 * A real table rather than a double, because the reason a refusal carries is read
 * off the challenge ROW — expired, consumed, out of attempts — and a double would
 * only be asserting that the test knows what it seeded. The log file is swapped for
 * a temporary one the way {@see DaemonManagerLostSignalLogTest} does it.
 *
 * The challenge is seeded through the object collection rather than issued, because
 * an issued secret is only ever mailed and a test cannot know it.
 */
final class VerificationConsumeLogIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_user_verification'];

    private const string TOKEN = 'tok-4242424242424242';

    private const string WRONG_TOKEN = 'tok-0000000000000000';

    private const int TTL_SECONDS = 900;

    /** Attempts one challenge gets in this case, small enough to exhaust in a short loop. */
    private const int MAX_ATTEMPTS = 2;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array VERIFICATION_KNOBS = [
        'HILOS_VERIFICATION_MAX_ATTEMPTS',
        'HILOS_VERIFICATION_TTL_SEC',
    ];

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

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

        $db = new ConsumeLogTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();

        putenv(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS->name . '=' . self::MAX_ATTEMPTS);
        putenv(EnvConstants::HILOS_VERIFICATION_TTL_SEC->name . '=' . self::TTL_SECONDS);

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-verification-consume-log');
        Logger::setLogFile($this->logFile);
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        foreach (self::VERIFICATION_KNOBS as $knob) {
            putenv($knob);
        }

        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * The line the report was missing: a click that worked says so, with the row to
     * look at and the budget it spent.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testAnAcceptedConsumeIsWrittenWithItsRowAndItsAttempts(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLink($email);

        self::assertTrue(new VerificationService()->verifyCode(VerificationType::MAGIC_LINK, $email, self::TOKEN));

        $written = $this->log();
        self::assertStringContainsString('verification consume accepted:', $written);
        self::assertStringContainsString('type=' . VerificationType::MAGIC_LINK, $written);
        self::assertStringContainsString('attempts=1/' . self::MAX_ATTEMPTS, $written);
        self::assertMatchesRegularExpression('/ id=\d+/', $written);
    }

    /**
     * A token that does not match names the mismatch and leaves the budget readable,
     * which is what tells a typo apart from a stale letter.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testAWrongSecretIsWrittenAsAMismatch(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLink($email);

        self::assertFalse(
            new VerificationService()->verifyCode(VerificationType::MAGIC_LINK, $email, self::WRONG_TOKEN),
        );

        $written = $this->log();
        self::assertStringContainsString('verification consume rejected:', $written);
        self::assertStringContainsString('reason=' . VerificationRejectReason::SECRET_MISMATCH, $written);
        self::assertStringContainsString('attempts=1/' . self::MAX_ATTEMPTS, $written);
    }

    /**
     * The ceiling voids the challenge by consuming it, so the two states overlap on
     * the row; the line has to name the more specific one or exhaustion is invisible.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testTheLastWrongAttemptIsWrittenAsExhaustionRatherThanMismatch(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLink($email);
        $service = new VerificationService();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            self::assertFalse($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::WRONG_TOKEN));
        }

        self::assertStringContainsString(
            'reason=' . VerificationRejectReason::ATTEMPTS_EXHAUSTED,
            $this->log(),
        );
    }

    /**
     * Clicking a link twice: the second one is a spent challenge, not a missing one,
     * and that difference is the whole reason the reasons are a closed set.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testASecondClickOnASpentLinkIsWrittenAsConsumed(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLink($email);
        $service = new VerificationService();

        self::assertTrue($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::TOKEN));
        self::assertFalse($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::TOKEN));

        self::assertStringContainsString('reason=' . VerificationRejectReason::CONSUMED, $this->log());
    }

    /**
     * Nothing was ever issued for this address: the case that used to look exactly
     * like a click that never arrived.
     *
     * @throws HilosException When a verification query fails
     */
    public function testAnAddressWithNoChallengeIsWrittenAsSuchWithNoRowToBlame(): void
    {
        $email = $this->uniqueEmail();

        self::assertFalse(new VerificationService()->verifyCode(VerificationType::MAGIC_LINK, $email, self::TOKEN));

        $written = $this->log();
        self::assertStringContainsString('reason=' . VerificationRejectReason::NO_CHALLENGE, $written);
        self::assertStringContainsString('attempts=0/' . self::MAX_ATTEMPTS, $written);
        self::assertStringContainsString(' id=-', $written);
    }

    /**
     * The condition that makes the whole line permissible: neither the secret nor the
     * address it was sent to is in it.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testNeitherTheSecretNorTheWholeAddressIsEverWritten(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLink($email);
        $service = new VerificationService();

        self::assertFalse($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::WRONG_TOKEN));
        self::assertTrue($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::TOKEN));

        $written = $this->log();
        self::assertStringNotContainsString(self::TOKEN, $written);
        self::assertStringNotContainsString(self::WRONG_TOKEN, $written);
        self::assertStringNotContainsString($email, $written);
        // Masked, not absent: an operator still has to tell one person's line from
        // another's, which is what the domain and the kept characters are for.
        self::assertStringContainsString('@example.test', $written);
    }

    /**
     * @return string Everything written to the log so far in this case
     */
    private function log(): string
    {
        return (string)file_get_contents($this->logFile);
    }

    /**
     * Seeds a live magic-link challenge with a token this case knows.
     *
     * @param string $email Address the letter was issued for
     * @throws HilosException When the challenge insert fails
     */
    private function seedLink(string $email): void
    {
        $this->verifications()->createChallenge(
            VerificationType::MAGIC_LINK,
            $email,
            null,
            self::TOKEN,
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
final class ConsumeLogTestDbContext extends HilosDbContext
{
}
