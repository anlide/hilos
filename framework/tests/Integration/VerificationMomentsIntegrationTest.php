<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The moments of a verification challenge, now that the row keeps them in epoch milliseconds (HIL-1921).
 *
 * Every rule of the challenge is a comparison of two moments - the expiry with now, the newest
 * issue plus the cooldown with now, an issue with the start of the send window - and each of them
 * is pinned here on the millisecond it turns on, against a real table. No case waits for the
 * clock: a moment the case needs in the past is written there, and a moment the code stamps
 * itself is read between two readings of the clock taken before and after the stamping call.
 *
 * The environment is the one {@see VerificationSpendRaceIntegrationTest} raises: the person and
 * challenge tables from their stubs, a framework context of its own, and the knobs of the send
 * gate set to numbers this case states.
 */
final class VerificationMomentsIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = [
        'hilos_user',
        'hilos_user_verification',
    ];

    private const string PHONE = '+15551921000';

    private const string CHANNEL = 'sms';

    private const string CODE = '192119';

    private const int TTL_SECONDS = 900;

    private const int COOLDOWN_SECONDS = 60;

    private const int WINDOW_SECONDS = 3600;

    /** Sends one number may have per window; far above what a case issues, so the cap never answers. */
    private const int SEND_CAP = 10;

    private const int MAX_ATTEMPTS = 4;

    /**
     * What is left of the cooldown when a case reads it, in milliseconds.
     *
     * A remainder shorter than a second, and shorter than half of one, so that cutting it to whole
     * seconds and rounding it both answer zero where only rounding up answers one. The single
     * millisecond the rule turns on cannot be held still without a clock of the case's own, so the
     * rest of the half second is the room the case's own statements run in.
     */
    private const int SHORT_HOLD_MS = 499;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array VERIFICATION_KNOBS = [
        'HILOS_VERIFICATION_TTL_SEC',
        'HILOS_VERIFICATION_RESEND_COOLDOWN_SEC',
        'HILOS_VERIFICATION_SEND_WINDOW_SEC',
        'HILOS_VERIFICATION_SEND_CAP_SMS',
        'HILOS_VERIFICATION_MAX_ATTEMPTS',
    ];

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** Raises the tables from their stubs and puts a framework context of its own in front of them. */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new VerificationMomentsTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();

        putenv(EnvConstants::HILOS_VERIFICATION_TTL_SEC->name . '=' . self::TTL_SECONDS);
        putenv(EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name . '=' . self::COOLDOWN_SECONDS);
        putenv(EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC->name . '=' . self::WINDOW_SECONDS);
        putenv(EnvConstants::HILOS_VERIFICATION_SEND_CAP_SMS->name . '=' . self::SEND_CAP);
        putenv(EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS->name . '=' . self::MAX_ATTEMPTS);
    }

    /** Puts back the knobs and the context, and drops the tables. */
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

    /** The issue stamps the millisecond it happened, and the expiry is that millisecond plus the lifetime, read back as stored. */
    public function testAnIssuedCodeStoresItsMomentsInMillisecondsAndExpiresALifetimeAfterIt(): void
    {
        $service = new VerificationService();

        $before = TimeHelper::nowMs();
        $issued = $service->issueForChannel(VerificationType::SMS_LOGIN, self::PHONE, null, self::CHANNEL);
        $after = TimeHelper::nowMs();

        self::assertTrue($issued->outcome->sent);
        $stored = $this->storedChallenges(self::PHONE);
        self::assertCount(1, $stored);
        [$createdAt, $expiresAt, $consumedAt] = $stored[0];
        self::assertGreaterThanOrEqual($before, $createdAt);
        self::assertLessThanOrEqual($after, $createdAt);
        self::assertSame($createdAt + self::TTL_SECONDS * TimeConstants::MS_PER_SECOND, $expiresAt);
        self::assertNull($consumedAt);
        self::assertSame($expiresAt, $service->activeExpiresAt(VerificationType::SMS_LOGIN, self::PHONE));
    }

    /** The millisecond before the stored expiry is still live; the expiry itself is not. */
    public function testAChallengeIsLiveUntilTheMillisecondItExpiresOn(): void
    {
        $challenge = $this->issue();
        $expiresAt = $this->storedChallenges(self::PHONE)[0][1];

        self::assertTrue($challenge->isActive($expiresAt - 1, self::MAX_ATTEMPTS));
        self::assertFalse($challenge->isActive($expiresAt, self::MAX_ATTEMPTS));
    }

    /**
     * Less than half a second of cooldown left still holds the send, and is counted as a whole second.
     *
     * A countdown that showed zero here would offer a button the gate still refuses.
     */
    public function testTheLastMillisecondsOfTheCooldownStillHoldTheSendForAWholeSecond(): void
    {
        $service = new VerificationService();
        $this->issue()->backdate(TimeHelper::nowMs() - self::COOLDOWN_SECONDS * TimeConstants::MS_PER_SECOND + self::SHORT_HOLD_MS);

        self::assertSame(1, $service->resendAllowedInSeconds(VerificationType::SMS_LOGIN, self::PHONE));
        $refused = $service->issueForChannel(VerificationType::SMS_LOGIN, self::PHONE, null, self::CHANNEL);
        self::assertFalse($refused->outcome->sent);
        self::assertFalse($refused->outcome->capReached);
        self::assertSame(1, $refused->outcome->resendInSeconds);
        self::assertNull($refused->code);
        self::assertCount(1, $this->storedChallenges(self::PHONE), 'A held send mints nothing');
    }

    /** A cooldown whose last millisecond is behind now holds nothing, and the send goes through. */
    public function testACooldownThatEndedLetsTheSendThrough(): void
    {
        $service = new VerificationService();
        $this->issue()->backdate(TimeHelper::nowMs() - self::COOLDOWN_SECONDS * TimeConstants::MS_PER_SECOND);

        self::assertSame(0, $service->resendAllowedInSeconds(VerificationType::SMS_LOGIN, self::PHONE));
        $sent = $service->issueForChannel(VerificationType::SMS_LOGIN, self::PHONE, null, self::CHANNEL);
        self::assertTrue($sent->outcome->sent);
        self::assertCount(2, $this->storedChallenges(self::PHONE));
    }

    /** A resend is allowed at the stored issue moment plus the cooldown, to the millisecond. */
    public function testAResendIsAllowedAtTheIssueMomentPlusTheCooldown(): void
    {
        $service = new VerificationService();
        $service->issueForChannel(VerificationType::SMS_LOGIN, self::PHONE, null, self::CHANNEL);
        $createdAt = $this->storedChallenges(self::PHONE)[0][0];

        self::assertSame(
            $createdAt + self::COOLDOWN_SECONDS * TimeConstants::MS_PER_SECOND,
            $service->resendAllowedAt(VerificationType::SMS_LOGIN, self::PHONE),
        );
    }

    /** Once the cooldown is over, the moment a resend is allowed at is now, not a moment in the past. */
    public function testAResendWhoseCooldownEndedIsAllowedNow(): void
    {
        $service = new VerificationService();
        $this->issue()->backdate(TimeHelper::nowMs() - self::COOLDOWN_SECONDS * TimeConstants::MS_PER_SECOND - 1);

        $before = TimeHelper::nowMs();
        $allowedAt = $service->resendAllowedAt(VerificationType::SMS_LOGIN, self::PHONE);
        $after = TimeHelper::nowMs();

        self::assertGreaterThanOrEqual($before, $allowedAt);
        self::assertLessThanOrEqual($after, $allowedAt);
    }

    /**
     * The window counts an issue newer than its start and nothing older or on the start itself.
     *
     * The start is taken before the read, so the row stamped on it is on or past the start the
     * read computes, and must be left out of the count.
     */
    public function testTheSendWindowCountsOnlyIssuesNewerThanItsStart(): void
    {
        $windowStart = TimeHelper::nowMs() - self::WINDOW_SECONDS * TimeConstants::MS_PER_SECOND;
        $newest = $windowStart + (self::WINDOW_SECONDS - 1) * TimeConstants::MS_PER_SECOND;
        $this->issue()->backdate($windowStart - 1);
        $this->issue()->backdate($windowStart);
        $this->issue()->backdate($windowStart + TimeConstants::SECONDS_PER_MINUTE * TimeConstants::MS_PER_SECOND);
        $this->issue()->backdate($newest);

        $stats = $this->verifications()->sendStats(VerificationType::SMS_LOGIN, self::PHONE, self::WINDOW_SECONDS);

        self::assertSame(2, $stats->sentInWindow);
        self::assertSame($newest, $stats->lastIssuedAt);
    }

    /** Spending a code stamps the millisecond it happened, on the row and on the worker's own copy. */
    public function testSpendingACodeStoresTheMillisecondItHappened(): void
    {
        $challenge = $this->issue();

        $before = TimeHelper::nowMs();
        self::assertTrue($challenge->consume());
        $after = TimeHelper::nowMs();

        $consumedAt = $this->storedChallenges(self::PHONE)[0][2];
        self::assertNotNull($consumedAt);
        self::assertGreaterThanOrEqual($before, $consumedAt);
        self::assertLessThanOrEqual($after, $consumedAt);
        self::assertSame($consumedAt, $challenge->consumedAt);
    }

    /**
     * Writes a challenge past the send gate, the way the gate's own issue writes it.
     *
     * @return ObjectUserVerification The new challenge
     */
    private function issue(): ObjectUserVerification
    {
        return $this->verifications()->createChallenge(
            VerificationType::SMS_LOGIN,
            self::PHONE,
            null,
            self::CODE,
            self::TTL_SECONDS,
            self::CHANNEL,
        );
    }

    /**
     * @param string $identifier Number the challenges were issued to
     * @return list<array{int, int, ?int}> Creation, expiry and spend of every challenge as stored, oldest first
     */
    private function storedChallenges(string $identifier): array
    {
        $rows = Database::sql(
            'SELECT `' . EntityUserVerification::created_at . '`, `' . EntityUserVerification::expires_at . '`, `'
                . EntityUserVerification::consumed_at . '` FROM `' . EntityUserVerification::_table
                . '` WHERE `' . EntityUserVerification::identifier . '` = ? ORDER BY `' . EntityUserVerification::id . '`',
            [$identifier],
        )->rows();

        return array_map(static fn (array $row): array => [
            (int)$row[EntityUserVerification::created_at],
            (int)$row[EntityUserVerification::expires_at],
            $row[EntityUserVerification::consumed_at] === null ? null : (int)$row[EntityUserVerification::consumed_at],
        ], $rows);
    }

    /** @return ObjectUserVerifications Verification primitives of this case's context */
    private function verifications(): ObjectUserVerifications
    {
        /** @var ObjectUserVerifications $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);

        return $collection;
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
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

/** A framework database context with nothing but the framework's own collections. */
final class VerificationMomentsTestDbContext extends HilosDbContext
{
}
