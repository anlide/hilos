<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Verification\VerificationService;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Object\Item\UserVerification as ObjectUserVerification;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Two workers racing for one code, and only one of them getting it (HIL-679), and
 * spending one budget of guesses between them rather than a budget each (HIL-715).
 *
 * Single-use used to rest on {@see ObjectUserVerifications::findActive()} no longer
 * matching a spent row, which is a rule the second worker had already passed by the
 * time the first one wrote: both saw a live challenge, both spent it, both were told
 * yes. Recovery is where that hurt - two devices on the new-password screen each
 * wrote their own secret and the last one won, having told the first it worked.
 *
 * The second worker is a second {@see DbContext}, not a second process. What makes
 * the race real is not concurrency but the object cache: a collection reuses the
 * instance it already hydrated, so a context whose
 * {@see ObjectUserVerifications::findActive()} ran BEFORE someone else spent the row
 * goes on reading its own copy as live for the rest of the process - which is
 * exactly the state a parallel worker is in, and is reproducible in one process
 * where real threads are not. Both contexts share the one connection, so what is
 * being pinned is the write, not the isolation level.
 *
 * A real table rather than a double, because the whole fix lives in a WHERE clause
 * and an affected-row count; a double would assert that the test knows what it
 * seeded. The challenge is seeded through the object collection rather than issued,
 * because an issued code is only ever mailed and a test cannot know it.
 *
 * The attempt ceiling is the same defect one door earlier and is pinned here on the
 * same fixture (HIL-715): it too was judged against each worker's cached copy, so two
 * workers got the ceiling each and the row recorded twice as many guesses as it was
 * ever supposed to allow.
 */
final class VerificationSpendRaceIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_user_verification'];

    private const string CODE = '424242';

    /** A code that is not {@see CODE}: what a guess looks like. */
    private const string WRONG_CODE = '000000';

    private const int TTL_SECONDS = 900;

    /** Attempts one code gets in this case; a race costs one on each side. */
    private const int MAX_ATTEMPTS = 4;

    /** @var list<string> Environment names this case sets and has to unset again */
    private const array VERIFICATION_KNOBS = [
        'HILOS_VERIFICATION_MAX_ATTEMPTS',
        'HILOS_VERIFICATION_TTL_SEC',
    ];

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?SpendRaceTestDbContext $first = null;

    private ?SpendRaceTestDbContext $second = null;

    /**
     * @throws HilosException When a stub statement fails or a context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $this->first = new SpendRaceTestDbContext();
        $this->first->configure();
        $this->second = new SpendRaceTestDbContext();
        $this->second->configure();

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

        $this->first = null;
        $this->second = null;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * The recovery case from the report: two saves, one password.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testOnlyOneOfTwoWorkersSpendingTheSameChallengeIsToldItSpentIt(): void
    {
        $email = $this->seedRacedCode();
        $service = new VerificationService();

        $this->asWorker($this->first);
        self::assertTrue($service->consumeActive(VerificationType::PASSWORD_RESET, $email));

        $this->asWorker($this->second);
        self::assertFalse(
            $service->consumeActive(VerificationType::PASSWORD_RESET, $email),
            'The worker that lost the race must be told so, not handed the same ticket twice',
        );
    }

    /**
     * The same race one door earlier, where the code is still on the wire: a correct
     * code buys the login for whoever wrote the row, and nothing for the other.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testACorrectCodeSubmittedTwiceAtOnceIsAcceptedOnlyOnce(): void
    {
        $email = $this->seedRacedCode();
        $service = new VerificationService();

        $this->asWorker($this->first);
        self::assertTrue($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::CODE));

        $this->asWorker($this->second);
        self::assertFalse(
            $service->verifyCode(VerificationType::MAGIC_LINK, $email, self::CODE),
            'A matching code is not enough: the challenge behind it was already spent',
        );
    }

    /**
     * The primitive itself, and the row underneath it: the loser writes nothing.
     *
     * The winner's stamp is aged by an hour before the loser tries, so that "the row
     * is unchanged" can only mean "the loser did not write" - a loser stamping the
     * current second would otherwise be indistinguishable from the winner, whose
     * stamp a race puts in that same second.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testTheLosingSpendLeavesTheWinnersStampOnTheRow(): void
    {
        $email = $this->seedRacedCode();

        $winner = $this->challengeSeenBy($this->first, $email, VerificationType::PASSWORD_RESET);
        $loser = $this->challengeSeenBy($this->second, $email, VerificationType::PASSWORD_RESET);
        self::assertSame($winner->id, $loser->id, 'Both workers must be racing for one row');

        self::assertTrue($winner->consume());
        $stamp = $this->ageStoredStampByAnHour((int)$winner->id);

        self::assertFalse($loser->consume(), 'A spent row must refuse the second spend');
        self::assertSame($stamp, $this->storedStampOf((int)$loser->id));
        self::assertNotNull(
            $loser->consumedAt,
            'The loser still has to stop reading as live, or its cached copy loops forever',
        );
    }

    /**
     * The report's own probe: two workers guessing at one code, turn by turn.
     *
     * Each of them submits a wrong code as many times as the ceiling allows, so a
     * ceiling held per worker would let the row record twice the ceiling — which is
     * what it did, measured, before the budget moved into the write's condition.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testTwoWorkersShareOneAttemptBudgetRatherThanGettingOneEach(): void
    {
        $email = $this->seedRacedCode();
        $service = new VerificationService();
        $id = (int)$this->challengeSeenBy($this->first, $email, VerificationType::MAGIC_LINK)->id;

        for ($round = 0; $round < self::MAX_ATTEMPTS; $round++) {
            foreach ([$this->first, $this->second] as $worker) {
                $this->asWorker($worker);
                self::assertFalse($service->verifyCode(VerificationType::MAGIC_LINK, $email, self::WRONG_CODE));
            }
        }

        self::assertSame(
            self::MAX_ATTEMPTS,
            $this->storedAttemptsOf($id),
            'One code is one budget of guesses: a ceiling held per worker records twice it',
        );
    }

    /**
     * The primitive itself: an attempt the ceiling refuses costs the row nothing.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testAnAttemptRefusedByTheCeilingLeavesTheRowUnchanged(): void
    {
        $email = $this->seedRacedCode();
        $challenge = $this->challengeSeenBy($this->first, $email, VerificationType::PASSWORD_RESET);
        $id = (int)$challenge->id;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            self::assertTrue($challenge->incrementAttempts(self::MAX_ATTEMPTS));
        }
        self::assertSame(self::MAX_ATTEMPTS, $this->storedAttemptsOf($id));

        self::assertFalse(
            $challenge->incrementAttempts(self::MAX_ATTEMPTS),
            'A row standing at the ceiling has nothing left to record an attempt against',
        );
        self::assertSame(
            self::MAX_ATTEMPTS,
            $this->storedAttemptsOf($id),
            'A refused attempt must leave the counter exactly where it found it',
        );
    }

    /**
     * The refusal has to reach the worker's own copy, not just the row.
     *
     * A worker whose cached challenge is behind the row is the one that gets refused,
     * and it is also the one still telling a person the code is live. So the refusal
     * re-reads the counter onto that copy, and every reader judging by it — the
     * collection's lookup and {@see VerificationService::hasActive()} — turns over
     * with it.
     *
     * @throws HilosException When the challenge insert or a verification query fails
     */
    public function testTheRefusedWorkerStopsSeeingTheChallengeAsLive(): void
    {
        $email = $this->seedRacedCode();
        $service = new VerificationService();

        $ahead = $this->challengeSeenBy($this->first, $email, VerificationType::PASSWORD_RESET);
        $behind = $this->challengeSeenBy($this->second, $email, VerificationType::PASSWORD_RESET);

        $this->asWorker($this->first);
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            self::assertTrue($ahead->incrementAttempts(self::MAX_ATTEMPTS));
        }

        $this->asWorker($this->second);
        self::assertFalse($behind->incrementAttempts(self::MAX_ATTEMPTS));
        self::assertSame(
            self::MAX_ATTEMPTS,
            $behind->attempts,
            'A refused worker has to take the row\'s count for its own, or it keeps promising a live code',
        );
        self::assertNull(
            $this->verifications()->findActive(VerificationType::PASSWORD_RESET, $email, self::MAX_ATTEMPTS),
            'The exhausted challenge must stop being found by the worker that was refused',
        );
        self::assertFalse($service->hasActive(VerificationType::PASSWORD_RESET, $email));
    }

    /**
     * Seeds a live challenge and hands both workers a look at it.
     *
     * The look is what arms the race: each context caches its own object of the row
     * while it is still unconsumed, which is the state two parallel workers are in
     * the instant before either of them writes.
     *
     * @return string Address the seeded challenge belongs to
     * @throws HilosException When the challenge insert or a verification query fails
     */
    private function seedRacedCode(): string
    {
        $email = RandomHelper::hex(8) . '@example.test';

        $this->asWorker($this->first);
        $this->verifications()->createChallenge(
            VerificationType::PASSWORD_RESET,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );
        $this->verifications()->createChallenge(
            VerificationType::MAGIC_LINK,
            $email,
            null,
            self::CODE,
            self::TTL_SECONDS,
        );

        foreach ([$this->first, $this->second] as $worker) {
            $this->asWorker($worker);
            foreach ([VerificationType::PASSWORD_RESET, VerificationType::MAGIC_LINK] as $type) {
                $this->verifications()->findActive($type, $email, self::MAX_ATTEMPTS);
            }
        }

        return $email;
    }

    /**
     * @param ?SpendRaceTestDbContext $worker Context whose cached view of the row is wanted
     * @param string $email Address the challenge belongs to
     * @param string $type Verification type the challenge was seeded under (see VerificationType)
     * @return ObjectUserVerification The challenge as that worker sees it
     * @throws HilosException When the lookup fails or the worker no longer sees a live challenge
     */
    private function challengeSeenBy(
        ?SpendRaceTestDbContext $worker,
        string $email,
        string $type,
    ): ObjectUserVerification {
        $this->asWorker($worker);
        $challenge = $this->verifications()->findActive($type, $email, self::MAX_ATTEMPTS);
        self::assertNotNull($challenge);

        return $challenge;
    }

    /**
     * Moves the stored consume stamp an hour into the past.
     *
     * @param int $id Challenge row to age
     * @return string The stamp now stored on the row
     * @throws HilosException When the update or the read-back fails
     */
    private function ageStoredStampByAnHour(int $id): string
    {
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `' . EntityUserVerification::consumed_at
                . '` = `' . EntityUserVerification::consumed_at . '` - INTERVAL 1 HOUR'
                . ' WHERE `' . EntityUserVerification::id . '` = ?',
            [$id],
        );

        return $this->storedStampOf($id);
    }

    /**
     * @param int $id Challenge row to read
     * @return ?string Consume stamp stored on the row, or null while it is unspent
     * @throws HilosException When the query fails
     */
    private function storedStampOf(int $id): ?string
    {
        Database::sql(
            'SELECT `' . EntityUserVerification::consumed_at . '` FROM `' . EntityUserVerification::_table
                . '` WHERE `' . EntityUserVerification::id . '` = ?',
            [$id],
        );
        $row = Database::row();
        self::assertNotNull($row);

        $stamp = $row[EntityUserVerification::consumed_at];

        return $stamp === null ? null : (string)$stamp;
    }

    /**
     * @param int $id Challenge row to read
     * @return int Attempts recorded on the row
     * @throws HilosException When the query fails
     */
    private function storedAttemptsOf(int $id): int
    {
        Database::sql(
            'SELECT `' . EntityUserVerification::attempts . '` FROM `' . EntityUserVerification::_table
                . '` WHERE `' . EntityUserVerification::id . '` = ?',
            [$id],
        );
        $row = Database::row();
        self::assertNotNull($row);

        return (int)$row[EntityUserVerification::attempts];
    }

    /**
     * Puts one of the two contexts in front of the verification layer.
     *
     * @param ?SpendRaceTestDbContext $worker Context the next calls read and write through
     */
    private function asWorker(?SpendRaceTestDbContext $worker): void
    {
        Hilos::$db = $worker;
    }

    /**
     * @return ObjectUserVerifications Verification primitives of the current worker
     * @throws HilosException When the collection is unavailable
     */
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
 * Two instances of it are what stands in for two workers here: the class is ordinary,
 * and everything the race needs comes from each instance owning its own collections.
 */
final class SpendRaceTestDbContext extends HilosDbContext
{
}
