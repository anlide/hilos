<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Tests\Unit\Core\CLI\Commands\UserTestFixtureCommandsTest;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises real commits and rollbacks through the users owner's command handler. */
final class TestUserCreateCommandRouteIntegrationTest extends HilosSessionIntegrationTestCase
{
    private string $previousAppClass;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;
    private TestUserCreateRouteLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        TestUserCreateLegalHilos::initBrowser();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        $this->library = new TestUserCreateRouteLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
        Database::configure(
            index: 1,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(1);
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    /** @return iterable<string, array{?string, ?string, ?string}> Identity and theme choices */
    public static function creations(): iterable
    {
        yield 'password null theme' => [UserTestFixtureCommandsTest::FIXTURE_PASSWORD_HASH, null, null];
        yield 'password SMS dark' => [UserTestFixtureCommandsTest::FIXTURE_PASSWORD_HASH, '+48123456789', 'dark'];
        yield 'passwordless light' => [null, null, 'light'];
        yield 'passwordless SMS system' => [null, '+48123456789', 'system'];
    }

    /**
     * @param ?string $hash Precomputed hash or passwordless
     * @param ?string $phone Optional SMS identity
     * @param ?string $theme Optional theme
     */
    #[DataProvider('creations')]
    public function testCreatesCommittedVerifiedIdentitiesAndCurrentAcceptances(?string $hash, ?string $phone, ?string $theme): void
    {
        $reply = $this->command(['email' => ' Fixture@Example.test ', 'passwordHash' => $hash, 'themePick' => $theme, 'phone' => $phone]);
        self::assertTrue($reply->isOk(), json_encode($reply->payload));
        $userId = $reply->payload['userId'];
        self::assertIsInt($userId);
        self::assertGreaterThan(0, $userId);
        self::assertSame(['userId' => $userId, 'email' => 'fixture@example.test', 'name' => 'fixture'], $reply->payload);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([[ $userId, 'fixture@example.test', false ]], $this->library->hooks);
        self::assertSame([1], $this->library->committedRowsAtHook);
        self::assertSame($phone === null ? 4 : 5, $this->library->syncFrames);

        $person = Database::sql('SELECT `name`, `theme_pick`, `theme_pick_initialized` FROM `hilos_user` WHERE `id` = ?', [$userId], index: 1);
        $row = $person->first()->row();
        self::assertSame(['name' => 'fixture', 'theme_pick' => $theme, 'theme_pick_initialized' => $theme === null ? '0' : '1'], $row);
        $identities = Database::sql('SELECT `user_id`, `type`, `identifier`, `secret`, `verified` FROM `hilos_identity` ORDER BY `id`', index: 1);
        $expected = [['user_id' => (string)$userId, 'type' => $hash === null ? 'magic_link' : 'password',
            'identifier' => 'fixture@example.test', 'secret' => $hash, 'verified' => '1']];
        if ($phone !== null) {
            $expected[] = ['user_id' => (string)$userId, 'type' => 'sms', 'identifier' => $phone, 'secret' => null, 'verified' => '1'];
        }
        self::assertSame($expected, $identities->first()->rows());
        $acceptances = Database::sql('SELECT `user_id`, `document`, `revision_id` FROM `hilos_legal_acceptance` ORDER BY `document`', index: 1);
        self::assertSame([
            ['user_id' => (string)$userId, 'document' => 'privacy', 'revision_id' => 'privacy-current'],
            ['user_id' => (string)$userId, 'document' => 'terms', 'revision_id' => 'terms-current'],
        ], $acceptances->first()->rows());
    }

    public function testTheSharedFixtureHashMatchesThePasswordWithoutHashingInTheHandler(): void
    {
        self::assertTrue(password_verify(UserTestFixtureCommandsTest::PASSWORD, UserTestFixtureCommandsTest::FIXTURE_PASSWORD_HASH));
    }

    public function testNoCatalogAndOmittedPhoneCreateOneIdentityAndNoAcceptances(): void
    {
        TestUserCreateEmptyHilos::initBrowser();
        self::assertTrue($this->command(['email' => 'empty@example.test', 'passwordHash' => null, 'themePick' => null])->isOk());
        self::assertSame([1, 1, 0], self::rowCounts());
        self::assertCount(1, $this->library->hooks);
    }

    public function testFailureAfterTheFirstWriteRollsBackAndPublishesNoPartialAccount(): void
    {
        $this->library->failAfterCreate = true;
        $reply = $this->command(self::payload());
        self::assertFalse($reply->isOk());
        self::assertSame(1, $this->library->createCalls);
        self::assertSame([0, 0, 0], self::rowCounts());
        self::assertArrayNotHasKey($this->library->attemptedUserId, Hilos::$db->users);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], $this->library->hooks);
        self::assertSame(0, $this->library->syncFrames);
    }

    public function testDuplicateEmailAndPhoneRollBackWithoutAnOrphanOrSecondHook(): void
    {
        $payload = [...self::payload(), 'phone' => '+48123456789'];
        $created = $this->command($payload);
        self::assertTrue($created->isOk());
        foreach ([$payload, [...$payload, 'email' => 'different@example.test']] as $duplicate) {
            $beforeSync = $this->library->syncFrames;
            self::assertFalse($this->command($duplicate)->isOk());
            self::assertSame([1, 2, 2], self::rowCounts());
            self::assertArrayNotHasKey($this->library->attemptedUserId, Hilos::$db->users);
            self::assertFalse(Database::hasOpenTransactions());
            self::assertCount(1, $this->library->hooks);
            self::assertSame($beforeSync, $this->library->syncFrames);
        }
    }

    /** @return iterable<string, array{array}> Invalid JSON payloads */
    public static function invalidPayloads(): iterable
    {
        foreach (['email', 'passwordHash', 'themePick'] as $key) {
            $payload = self::payload();
            unset($payload[$key]);
            yield 'missing ' . $key => [$payload];
        }
        foreach ([false, 1, [], null, '', 'invalid'] as $i => $email) {
            yield 'email ' . $i => [[...self::payload(), 'email' => $email]];
        }
        foreach ([false, 1, [], '', 'plaintext', '$2y$broken'] as $i => $hash) {
            yield 'hash ' . $i => [[...self::payload(), 'passwordHash' => $hash]];
        }
        foreach ([false, 1, [], '', 'blue'] as $i => $theme) {
            yield 'theme ' . $i => [[...self::payload(), 'themePick' => $theme]];
        }
        foreach ([false, 1, [], '', '48123456789', '+48 123456789', '+01234567890', '+123'] as $i => $phone) {
            yield 'phone ' . $i => [[...self::payload(), 'phone' => $phone]];
        }
    }

    /** @param array $payload Malformed command payload */
    #[DataProvider('invalidPayloads')]
    public function testInvalidBoundaryOrValuesNeverReachTheFirstWrite(array $payload): void
    {
        self::assertFalse($this->command($payload)->isOk());
        self::assertSame(0, $this->library->createCalls);
        self::assertSame([0, 0, 0], self::rowCounts());
        self::assertSame([], $this->library->hooks);
    }

    /**
     * @param array<string, mixed> $payload Incoming JSON boundary
     * @return CommandReplyDTO Exactly one reply from the real handler
     */
    private function command(array $payload): CommandReplyDTO
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), fn () => $this->library->onSignalCommand(
            new CommandRequestDTO('create-cid', CliCommands::USER_TEST_CREATE, $payload), '', '',
        ));
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            } elseif ($signal->signalSource->getSource() === SignalSource::DB) {
                $this->library->syncFrames++;
            }
        }
        self::assertCount(1, $replies);
        self::assertSame('create-cid', $replies[0]->correlationId);

        return $replies[0];
    }

    /** @return array<string, mixed> Valid password fixture request */
    private static function payload(): array
    {
        return ['email' => 'fixture@example.test', 'passwordHash' => UserTestFixtureCommandsTest::FIXTURE_PASSWORD_HASH, 'themePick' => null];
    }

    /** @return list<int> Committed counts seen outside the handler's connection */
    private static function rowCounts(): array
    {
        $counts = [];
        foreach (['hilos_user', 'hilos_identity', 'hilos_legal_acceptance'] as $table) {
            $count = Database::sql('SELECT COUNT(*) AS n FROM `' . $table . '`', index: 1);
            $counts[] = (int)$count->first()->row()['n'];
        }

        return $counts;
    }
}

/** Keeps the owner's normal writes and provides a failure immediately after the user insert. */
final class TestUserCreateRouteLibrary extends AbstractUsersLibraryAgent
{
    public bool $failAfterCreate = false;
    public int $createCalls = 0;
    public ?int $attemptedUserId = null;
    public array $hooks = [];
    public array $committedRowsAtHook = [];
    public int $syncFrames = 0;

    /**
     * @param string $displayName Derived account name
     * @param ?string $themePick Account theme
     * @return int Real created row id
     */
    public function createUser(string $displayName, ?string $themePick): int
    {
        $this->createCalls++;
        $this->attemptedUserId = parent::createUser($displayName, $themePick);
        if ($this->failAfterCreate) {
            throw new ValidationException('Forced failure after user insert');
        }

        return $this->attemptedUserId;
    }

    /**
     * @param int $userId Created account
     * @param string $identifier Normalized email
     */
    public function afterUserCreated(int $userId, string $identifier): void
    {
        $this->hooks[] = [$userId, $identifier, Database::hasOpenTransactions()];
        $count = Database::sql('SELECT COUNT(*) AS n FROM `hilos_user` WHERE `id` = ?', [$userId], index: 1);
        $this->committedRowsAtHook[] = (int)$count->first()->row()['n'];
    }
}

final class TestUserCreateLegalCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Current and historical fixture revisions */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'terms-old', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'terms-current', '2026-02-01', 1, LegalSignificance::EDITORIAL, '2026-02-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy-current', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

abstract class TestUserCreateLegalHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = TestUserCreateLegalCatalog::class;
}

abstract class TestUserCreateEmptyHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = null;
}
