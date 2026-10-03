<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Verification\VerificationSweepCommandConstants;
use Hilos\Auth\Verification\VerificationSweepSettings;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Auth\Verification\VerificationSweeper;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use ReflectionProperty;

/** Exercises the real verification table through the users library's sweep (HIL-1163). */
final class VerificationSweepIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string ADDRESS = 'sweep@example.test';

    private ?HilosDbContext $previousDb = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?SignalRouter $previousRouter = null;

    /** @throws HilosException When a stub statement or context setup fails */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStubs(down: true);
        self::runStubs(down: false);
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousRouter = Hilos::$sr;
        $db = new VerificationSweepTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$setting = new VerificationSweepIntegrationSettings(VerificationSweepSettingsCatalog::class);
        Hilos::$sr = new SignalRouter();
        VerificationSweepIntegrationSettings::$retention = 3600;
        VerificationSweepIntegrationSettings::$cron = '* * * * *';
    }

    /** @throws HilosException When a stub statement fails */
    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        parent::tearDown();
    }

    /** @throws HilosException When seeding or sweeping fails */
    public function testSweepRemovesOnlyOldSpentOrExpiredRows(): void
    {
        self::seed(self::ADDRESS, old: true, expired: true, consumed: false);
        self::seed(self::ADDRESS, old: true, expired: false, consumed: true);
        self::seed(self::ADDRESS, old: true, expired: false, consumed: false);
        self::seed(self::ADDRESS, old: false, expired: true, consumed: true);

        self::assertSame(2, new VerificationSweeper()->sweep());
        self::assertSame(2, new VerificationSweeper()->countFor(self::ADDRESS));
    }

    /** @throws HilosException When seeding or sweeping fails */
    public function testRetentionChangesApplyToTheNextPassAndCannotUndercutTheSendWindow(): void
    {
        self::seed(self::ADDRESS, old: true, expired: true, consumed: false, ageSeconds: 5000);
        VerificationSweepIntegrationSettings::$retention = 7200;
        self::assertSame(0, new VerificationSweeper()->sweep());

        VerificationSweepIntegrationSettings::$retention = 60;
        self::assertSame(3600, VerificationSweepSettings::retentionSeconds());
        self::assertSame(1, new VerificationSweeper()->sweep());
    }

    /** @throws HilosException When seeding or sweeping fails */
    public function testAFullBatchContinuesOnTheNextTick(): void
    {
        for ($i = 0; $i <= VerificationSweeper::BATCH; $i++) {
            self::seed(self::ADDRESS, old: true, expired: true, consumed: false);
        }

        $library = new VerificationSweepTestLibrary();
        $library->onTick();
        self::schedule($library)->lastRun = 0.0;
        $library->onTick();
        self::assertSame(1, new VerificationSweeper()->countFor(self::ADDRESS));
        $library->onTick();
        self::assertSame(0, new VerificationSweeper()->countFor(self::ADDRESS));
    }

    /** @throws HilosException When seeding or commanding fails */
    public function testCommandAgesRowsSweepsAndRepliesOnce(): void
    {
        self::seed(self::ADDRESS, old: false, expired: true, consumed: false);
        self::seed(self::ADDRESS, old: false, expired: false, consumed: false);
        $library = new VerificationSweepTestLibrary();
        self::assertContains(CliCommands::VERIFICATION_TEST_SWEEP, $library::AGENT_COMMANDS);

        $library->onSignalCommand(new CommandRequestDTO('corr-sweep', CliCommands::VERIFICATION_TEST_SWEEP, [
            VerificationSweepCommandConstants::FIELD_IDENTIFIER => self::ADDRESS,
        ]), '', '');

        $reply = self::commandReply();
        self::assertInstanceOf(CommandReplyDTO::class, $reply);
        self::assertTrue($reply->isOk());
        self::assertSame([
            VerificationSweepCommandConstants::FIELD_IDENTIFIER => self::ADDRESS,
            VerificationSweepCommandConstants::FIELD_REMOVED => 1,
            VerificationSweepCommandConstants::FIELD_KEPT => 1,
        ], $reply->payload);
        $library->onSignalCommand(new CommandRequestDTO('corr-empty', CliCommands::VERIFICATION_TEST_SWEEP, [
            VerificationSweepCommandConstants::FIELD_IDENTIFIER => '',
        ]), '', '');
        $error = self::commandReply();
        self::assertInstanceOf(CommandReplyDTO::class, $error);
        self::assertFalse($error->isOk());
        self::assertSame(
            'Verification sweep requires a non-empty identifier',
            $error->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /** @throws HilosException When reading the schedule fails */
    public function testScheduleRebuildsWhenTheSettingChanges(): void
    {
        $library = new VerificationSweepTestLibrary();
        $library->onTick();
        self::assertSame('* * * * *', self::schedule($library)->expression);

        VerificationSweepIntegrationSettings::$cron = '5 * * * *';
        new ReflectionProperty(AbstractUsersLibraryAgent::class, 'verificationSweepCheckedMinute')->setValue($library, -1);
        $library->onTick();
        self::assertSame('5 * * * *', self::schedule($library)->expression);
    }

    /**
     * @param string $identifier Address on the row
     * @param bool $old Whether the row is past the default retention
     * @param bool $expired Whether the code expired
     * @param bool $consumed Whether the code was spent
     * @param int $ageSeconds Age of an old row
     * @throws DatabaseException When the insert fails
     */
    private static function seed(
        string $identifier,
        bool $old,
        bool $expired,
        bool $consumed,
        int $ageSeconds = 7200,
    ): void {
        $now = time();
        Database::sqlRun(
            'INSERT INTO `hilos_user_verification` (`type`, `identifier`, `created_at`, `expires_at`, `consumed_at`)'
                . ' VALUES (?, ?, ?, ?, ?)',
            [
                VerificationType::REGISTER_CONFIRM,
                $identifier,
                date('Y-m-d H:i:s', $now - ($old ? $ageSeconds : 60)),
                date('Y-m-d H:i:s', $now + ($expired ? -60 : 7200)),
                $consumed ? date('Y-m-d H:i:s', $now - 30) : null,
            ],
        );
    }

    /** @return CronRule The library's current sweep rule */
    private static function schedule(VerificationSweepTestLibrary $library): CronRule
    {
        $rule = new ReflectionProperty(AbstractUsersLibraryAgent::class, 'verificationSweepRule')->getValue($library);
        self::assertInstanceOf(CronRule::class, $rule);

        return $rule;
    }

    /** @return ?CommandReplyDTO The only command reply queued among row announcements */
    private static function commandReply(): ?CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies);

        return $replies[0] ?? null;
    }

    /** @throws HilosException When a stub statement fails */
    private static function runStubs(bool $down): void
    {
        $tables = $down ? ['hilos_user_verification', 'hilos_setting'] : ['hilos_setting', 'hilos_user_verification'];
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** Framework database context for the verification table. */
final class VerificationSweepTestDbContext extends HilosDbContext
{
}

/** Mutable stored values model an administrator changing a setting while the agent runs. */
final class VerificationSweepIntegrationSettings extends SettingsAccessor
{
    public static int $retention = 3600;
    public static string $cron = '* * * * *';

    /**
     * @param string $key Setting key
     * @return mixed Current scripted value
     */
    public function effectiveValueFor(string $key): mixed
    {
        return match ($key) {
            VerificationSweepSettings::RETENTION_SECONDS_KEY => self::$retention,
            VerificationSweepSettings::SWEEP_CRON_KEY => self::$cron,
            default => parent::effectiveValueFor($key),
        };
    }
}

/** Users library fixture that only sweeps verification rows. */
final class VerificationSweepTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @param string $displayName Proposed account name
     * @return int Never returns
     * @throws LogicException Always: this fixture creates no users
     */
    public function createUser(string $displayName): int
    {
        throw new LogicException('Verification sweep fixture creates no users');
    }

    /**
     * @param int $userId Person whose name is requested
     * @return ?string Always null
     */
    public function displayNameOf(int $userId): ?string
    {
        return null;
    }
}
