<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the test:user:seed CLI against the chat project: the command
 * seeds exactly N users each with a password identity, the once-computed shared hash
 * verifies for every seeded user, a second run on the same prefix fails (strictly
 * not idempotent), and the createPasswordIdentity() wrapper still produces a verifiable
 * identity after being reduced to a thin caller of createPasswordIdentityWithHash().
 * Requires the test DB to be reset before run (composer run test:db-reset).
 */
final class UserTestSeedCommandTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string SEED_PASSWORD = 'correct horse battery';
    private const int SEED_COUNT = 5;

    protected function setUp(): void
    {
        parent::setUp();
        // Direct identity writes (the wrapper regression) need a collection-wide truth
        // source; the command registers its own before mutating.
        TruthSourceRegistry::register(HilosDbContext::identities, true, self::TEST_AGENT_ID);
    }

    protected function tearDown(): void
    {
        // The command registers the identities truth source under its own id; drop it so
        // it does not leak into the next test in this process.
        TruthSourceRegistry::unregisterAgent('test-cli');
        parent::tearDown();
    }

    /**
     * Seeds N users and asserts N users, N unverified identities, and that the single
     * shared hash verifies the password for every one of them.
     *
     * @throws HilosException When a seed write or lookup fails
     */
    public function testSeedsExactlyNUsersWithVerifiablePasswordIdentities(): void
    {
        $prefix = 'itseed' . RandomHelper::hex(4);

        $exit = $this->runSeed($prefix, self::SEED_COUNT);
        $this->assertSame(ExitCode::SUCCESS, $exit);

        $userIds = [];
        for ($i = 1; $i <= self::SEED_COUNT; $i++) {
            $local = UserTestSeedCommand::fixtureIdentifier($i, self::SEED_COUNT, $prefix);
            $email = $local . '@example.test';

            $identity = Hilos::$db->identities->findByIdentity(IdentityType::PASSWORD, $email);
            $this->assertNotNull($identity, "identity missing for {$email}");
            $this->assertFalse($identity->verified);

            $userId = $identity->userId;
            $this->assertNotNull($userId);
            $this->assertSame($local, Hilos::$db->users[$userId]?->name);
            $userIds[$userId] = true;

            // Every seeded user verifies against the one shared hash.
            $secret = $this->readSecret($email);
            $this->assertIsString($secret);
            $this->assertTrue(password_verify(self::SEED_PASSWORD, $secret));
        }

        // Exactly N distinct users and N identities, no more.
        $this->assertCount(self::SEED_COUNT, $userIds);
        $this->assertSame(self::SEED_COUNT, $this->countPasswordIdentities($prefix . '-'));
    }

    /**
     * A second run on the same prefix collides on the first generated identity and fails
     * loudly (a stale, un-reset database would otherwise be seeded onto silently).
     *
     * @throws HilosException When the first seed run fails
     */
    public function testRerunWithSamePrefixFails(): void
    {
        $prefix = 'itrerun' . RandomHelper::hex(4);
        $this->assertSame(ExitCode::SUCCESS, $this->runSeed($prefix, self::SEED_COUNT));

        $command = new UserTestSeedCommand();
        ob_start();
        try {
            $this->expectException(DuplicateValueException::class);
            $command->execute(['prefix' => $prefix], [(string)self::SEED_COUNT]);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The createPasswordIdentity() wrapper, now delegating to createPasswordIdentityWithHash(),
     * still stores a verifiable hash for the registration path.
     *
     * @throws HilosException When the user or identity write fails
     */
    public function testCreatePasswordIdentityWrapperStillWorks(): void
    {
        $email = RandomHelper::hex(8) . '@example.test';
        $userId = Hilos::$db->users->actions->createWithName('wrapper-user')->id;
        $this->assertNotNull($userId);

        $identity = Hilos::$db->identities->createPasswordIdentity($userId, $email, self::SEED_PASSWORD);
        $this->assertFalse($identity->verified);
        $this->assertSame($userId, $identity->userId);

        $secret = $this->readSecret($email);
        $this->assertIsString($secret);
        $this->assertTrue(password_verify(self::SEED_PASSWORD, $secret));
    }

    /**
     * Runs the seed command, swallowing its stdout summary line.
     *
     * @param string $prefix Identifier prefix passed as --prefix
     * @param int $count How many users to seed
     * @return int Command exit code
     * @throws HilosException When the seed run fails
     */
    private function runSeed(string $prefix, int $count): int
    {
        ob_start();
        try {
            return new UserTestSeedCommand()->execute(['prefix' => $prefix], [(string)$count]);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Counts `password` identities whose identifier starts with the given prefix.
     *
     * @param string $localPrefix Identifier prefix to match (e.g. `itseedabcd-`)
     * @return int Number of matching identities
     * @throws HilosException When the count query fails
     */
    private function countPasswordIdentities(string $localPrefix): int
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($localPrefix . '%'));
        $resultSet = Database::sql(
            'SELECT COUNT(*) AS c FROM `' . EntityIdentity::_table . '` '
            . 'WHERE `' . EntityIdentity::type . '` = ? AND `' . EntityIdentity::identifier . '` LIKE ?',
            $params,
        )->first();
        $row = $resultSet?->first();

        return is_array($row) ? (int)($row['c'] ?? 0) : 0;
    }

    /**
     * Reads the stored password hash for a `password` identity by email.
     *
     * @param string $email Identity identifier
     * @return ?string Stored secret hash or null when absent
     * @throws HilosException When the lookup query fails
     */
    private function readSecret(string $email): ?string
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string(IdentityType::PASSWORD));
        $params->add(SqlParam::string($email));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` '
            . 'WHERE `' . EntityIdentity::type . '` = ? AND `' . EntityIdentity::identifier . '` = ?',
            $params,
        )->first();
        $row = $resultSet?->first();
        $secret = is_array($row) ? ($row[EntityIdentity::secret] ?? null) : null;

        return is_string($secret) ? $secret : null;
    }
}
