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
 * Two workers racing for one code, and only one of them getting it (HIL-679).
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
 */
final class VerificationSpendRaceIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_user_verification'];

    private const string CODE = '424242';

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

        $winner = $this->challengeSeenBy($this->first, $email);
        $loser = $this->challengeSeenBy($this->second, $email);
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
     * @return ObjectUserVerification The recovery challenge as that worker sees it
     * @throws HilosException When the lookup fails or the worker no longer sees a live challenge
     */
    private function challengeSeenBy(?SpendRaceTestDbContext $worker, string $email): ObjectUserVerification
    {
        $this->asWorker($worker);
        $challenge = $this->verifications()->findActive(VerificationType::PASSWORD_RESET, $email, self::MAX_ATTEMPTS);
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
