<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use FilesystemIterator;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsPersonEvent;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Seed;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Hilos;
use LogicException;
use mysqli;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Destructive DDL stays outside rollback scopes and uses its own database and registered FS.
 * Static backup also restores initially unconfigured migration/seed paths without private access.
 */
#[BackupStaticProperties(true)]
final class DbTestResetAnalyticsIntegrationTest extends TestCase
{
    public function testResetDoesNotReplayAnOldAccountsJournalOntoAReusedUserId(): void
    {
        $this->inIsolatedInstallation(function (string $root, mysqli $server, string $database): void {
            $this->createOldDatabase($server, $database, $root);
            Database::sqlRun("INSERT INTO `reset_account` (`name`) VALUES ('old account')");
            $oldUserId = Database::lastInsertId();
            $scope = $root . '/journal/test/node';
            $journal = new AnalyticsJournalDirectory($scope, 'reset-node');
            $journal->start();
            $journal->append($this->sessionLines('old-ready', $oldUserId), 4, 1000);
            $ready = $journal->rotate(1001);
            self::assertNotNull($ready);
            $portion = $journal->readPortion($ready, 0);
            self::assertNotNull($portion);
            new AnalyticsJournalLoader(new AnalyticsStore())->load('reset-node', $ready, $portion->lines);
            self::assertSame(1, new AnalyticsSectionReader()->browserSessionCount($oldUserId));
            $journal->append($this->sessionLines('old-open', $oldUserId), 4, 1002);
            // Leave the unrotated .open file for the next journal's startup recovery.
            unset($journal);
            file_put_contents($scope . '/losses.json', '{}');
            file_put_contents($scope . '/losses.json.tmp', '{}');
            file_put_contents($scope . '/.gitignore', '*');
            file_put_contents($scope . '/.gitkeep', '');
            $sentinels = $this->putSentinels($root);

            [$code, $output] = $this->executeReset();
            self::assertSame(ExitCode::SUCCESS, $code, $output);
            Database::sqlRun("INSERT INTO `reset_account` (`name`) VALUES ('new account')");
            $newUserId = Database::lastInsertId();
            self::assertSame($oldUserId, $newUserId);
            $remaining = scandir($scope);

            $journal = new AnalyticsJournalDirectory($scope, 'reset-node');
            $journal->start();
            $journal->append($this->sessionLines('new-session', $newUserId), 4, 2000);
            $journal->rotate(2001);
            $loader = new AnalyticsJournalLoader(new AnalyticsStore());
            while (($file = $journal->oldestReady()) !== null) {
                $portion = $journal->readPortion($file, 0);
                self::assertNotNull($portion);
                $loader->load('reset-node', $file, $portion->lines);
                $journal->delete($file);
            }

            self::assertSame(1, new AnalyticsSectionReader()->browserSessionCount($newUserId));
            self::assertInstanceOf(ResetAnalyticsFsContext::class, Hilos::$fs);
            self::assertSame(['.', '..', '.gitignore', '.gitkeep'], $remaining);
            self::assertDirectoryExists($scope);
            foreach ($sentinels as $path) {
                self::assertSame('keep', file_get_contents($path));
            }
        });
    }

    public function testRepeatedResetAndAnAbsentScopeSucceed(): void
    {
        $this->inIsolatedInstallation(function (string $root): void {
            foreach ([1, 2, 3] as $attempt) {
                [$code, $output] = $this->executeReset();
                self::assertSame(ExitCode::SUCCESS, $code, $output);
                self::assertStringContainsString('Done.', $output);
                Database::sql('SELECT COUNT(*) AS n FROM `reset_account`');
                self::assertSame(0, (int)Database::field('n'));
                if ($attempt === 1) {
                    mkdir($root . '/journal/test/node', 0777, true);
                    file_put_contents($root . '/journal/test/node/losses.json', '{}');
                } elseif ($attempt === 2) {
                    self::assertSame(['.', '..'], scandir($root . '/journal/test/node'));
                    rmdir($root . '/journal/test/node');
                }
            }
            self::assertDirectoryDoesNotExist($root . '/journal/test/node');
        });
    }

    /** @param string $failure Missing wiring or an unremovable scope entry */
    #[DataProvider('cleanupFailures')]
    public function testRefusalLeavesTheOldDatabaseIntact(string $failure): void
    {
        $this->inIsolatedInstallation(function (string $root, mysqli $server, string $database) use ($failure): void {
            $this->createOldDatabase($server, $database, $root);
            Database::sqlRun("INSERT INTO `reset_account` (`name`) VALUES ('sentinel')");
            $scope = $root . '/journal/test/node';
            if ($failure === 'null') {
                ResetAnalyticsHilos::$filesystem = null;
            } elseif ($failure === 'unregistered') {
                ResetAnalyticsHilos::$filesystem = new ResetAnalyticsFsContext($root, null);
            } elseif ($failure === 'cluster') {
                ResetAnalyticsHilos::$filesystem = new ResetAnalyticsFsContext($root, DirectoryScope::CLUSTER);
            } else {
                mkdir(dirname($scope), 0777, true);
                if ($failure === 'file') {
                    file_put_contents($scope, 'not a directory');
                } else {
                    mkdir($scope);
                    symlink($root . '/missing-target', $scope . '/undeletable');
                }
            }

            ob_start();
            try {
                if (in_array($failure, ['null', 'unregistered', 'cluster'], true)) {
                    $exception = null;
                    try {
                        new DbTestResetCommand()->execute([], []);
                    } catch (DirectoryNotFoundException | InvalidTopologyException $caught) {
                        $exception = $caught;
                    }
                    self::assertInstanceOf(
                        $failure === 'cluster' ? InvalidTopologyException::class : DirectoryNotFoundException::class,
                        $exception,
                    );
                } else {
                    self::assertSame(ExitCode::ERROR, new DbTestResetCommand()->execute([], []));
                    self::assertStringContainsString($failure === 'file' ? $scope : $scope . '/undeletable', ob_get_contents());
                }
            } finally {
                $output = ob_get_clean();
            }
            self::assertStringNotContainsString('Done.', $output);
            self::assertSame([['name' => 'sentinel']], $server->query('SELECT `name` FROM `' . $database . '`.`reset_account`')
                ->fetch_all(MYSQLI_ASSOC));
        });
    }

    /** @return array<string, array{string}> Refusals that must precede DROP */
    public static function cleanupFailures(): array
    {
        return ['null FS' => ['null'], 'missing registration' => ['unregistered'], 'wrong owner' => ['cluster'],
            'scope is a file' => ['file'], 'entry cannot be removed' => ['dangling']];
    }

    /** @return array<string, array{string, string}> Live neighboring and cluster targets at every scope boundary */
    public static function liveDirectoryLinks(): array
    {
        return [
            'scope to neighbor' => ['scope', 'neighbor'],
            'scope to cluster' => ['scope', 'cluster'],
            'component to neighbor' => ['component', 'neighbor'],
            'component to cluster' => ['component', 'cluster'],
            'nested to neighbor' => ['nested', 'neighbor'],
            'nested to cluster' => ['nested', 'cluster'],
        ];
    }

    /**
     * @param string $location Link position within the current journal scope
     * @param string $owner Neighboring NODE scope or registered CLUSTER directory
     */
    #[DataProvider('liveDirectoryLinks')]
    public function testDirectoryLinksRefuseBeforeDropAndKeepTheirTargets(string $location, string $owner): void
    {
        $this->inIsolatedInstallation(function (string $root, mysqli $server, string $database) use ($location, $owner): void {
            $this->createOldDatabase($server, $database, $root);
            Database::sqlRun("INSERT INTO `reset_account` (`name`) VALUES ('sentinel')");
            $target = $owner === 'cluster' ? $root . '/files' : $root . '/journal/prod/node';
            mkdir($target . '/node', 0777, true);
            $sentinels = [$target . '/sentinel', $target . '/node/sentinel',
                $target . '/' . ClusterDirectoryMarker::FILE_NAME, $target . '/node/' . ClusterDirectoryMarker::FILE_NAME];
            foreach ($sentinels as $path) {
                file_put_contents($path, 'keep');
            }
            $scope = $root . '/journal/test/node';
            if ($location === 'component') {
                if (!is_dir(dirname($scope, 2))) {
                    mkdir(dirname($scope, 2));
                }
                symlink($target, dirname($scope));
            } else {
                mkdir(dirname($scope), 0777, true);
                if ($location === 'nested') {
                    mkdir($scope);
                }
                symlink($target, $location === 'nested' ? $scope . '/linked' : $scope);
            }

            [$code, $output] = $this->executeReset();

            foreach ($sentinels as $path) {
                self::assertFileExists($path);
                self::assertSame('keep', file_get_contents($path));
            }
            self::assertSame(ExitCode::ERROR, $code, $output);
            self::assertStringContainsString($location === 'nested' ? $scope . '/linked' : $scope, $output);
            self::assertStringNotContainsString('Done.', $output);
            self::assertSame([['name' => 'sentinel']], $server->query('SELECT `name` FROM `' . $database . '`.`reset_account`')
                ->fetch_all(MYSQLI_ASSOC));
        });
    }

    public function testAProjectWithoutAnalyticsNeverInitializesItsFilesystem(): void
    {
        $this->inIsolatedInstallation(function (string $root): void {
            ResetWithoutAnalyticsHilos::initEnv($root);
            $sentinels = $this->putSentinels($root);
            [$code, $output] = $this->executeReset();
            self::assertSame(ExitCode::SUCCESS, $code, $output);
            self::assertNull(Hilos::$fs);
            foreach ($sentinels as $path) {
                self::assertSame('keep', file_get_contents($path));
            }
        });
    }

    /** @return array{int, string} Real command result and stdout */
    private function executeReset(): array
    {
        // A separate CLI invocation has neither a migration cache nor an open primary link.
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);
        ob_start();
        try {
            return [new DbTestResetCommand()->execute([], []), ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Uses public configuration APIs; finally restores configured targets before static backup
     * restores absent configurations and the suite's initially unconfigured paths.
     *
     * @param callable(string, mysqli, string): void $case Assertions against the disposable installation
     */
    private function inIsolatedInstallation(callable $case): void
    {
        $environment = Hilos::$env;
        $facade = Hilos::appClass();
        $filesystem = Hilos::$fs;
        $cluster = Hilos::$cluster;
        $primary = in_array(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getConfiguredIndices(), true)
            ? Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX) : null;
        $connected = Database::isConnected(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $currentIndex = Database::getCurrentIndex();
        try {
            $track = Migration::trackPath();
        } catch (DatabaseException) {
            $track = null;
        }
        $seeds = Seed::getSeedPath();
        $database = 'hilos-reset-analytics-' . bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . '/' . $database;
        $server = new mysqli($environment[EnvConstants::DB_HOST]->string(), 'root',
            $environment[EnvConstants::DB_ROOT_PASSWORD]->string(), '', $environment[EnvConstants::DB_PORT]->int());
        try {
            mkdir($root . '/Schema', 0777, true);
            mkdir($root . '/seeds');
            file_put_contents($root . '/Schema/001_account.sql',
                'CREATE TABLE `reset_account` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL);');
            copy(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_analytics.sql', $root . '/Schema/002_analytics.sql');
            Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Schema::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Hilos::$env = new ResetAnalyticsEnv($environment, $database);
            Hilos::$cluster = null;
            ResetAnalyticsHilos::initEnv($root);
            Hilos::$fs = null;
            ResetAnalyticsHilos::$filesystem = new ResetAnalyticsFsContext($root, DirectoryScope::NODE);
            Migration::setMigrationListPath($root);
            Migration::setMigrationName('Schema');
            if ($seeds !== null) {
                Seed::setSeedPath($root . '/seeds');
            }
            $case($root, $server, $database);
        } finally {
            Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Schema::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Migration::reset(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $server->close();
            if ($primary !== null) {
                Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, $primary->host, $primary->user, $primary->password,
                    $primary->database, $primary->port, $primary->charset, $primary->socket,
                    $primary->reconnectAttempts, $primary->reconnectDelay);
                if ($connected) {
                    Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
                }
            }
            Database::useConnection($currentIndex);
            if ($track !== null) {
                Migration::setMigrationListPath(dirname($track));
                Migration::setMigrationName(basename($track));
            }
            if ($seeds !== null) {
                Seed::setSeedPath($seeds);
            }
            Hilos::$env = new EnvAccessor();
            $facade::initEnv();
            Hilos::$env = $environment;
            Hilos::$fs = $filesystem;
            Hilos::$cluster = $cluster;
            ResetAnalyticsHilos::$filesystem = null;
            if (is_dir($root)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($root);
            }
        }
    }

    /**
     * @param mysqli $server Root connection to the fixture server
     * @param string $database Disposable database name
     * @param string $root Fixture migration directory
     */
    private function createOldDatabase(mysqli $server, string $database, string $root): void
    {
        $server->query('CREATE DATABASE `' . $database . '` ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, Hilos::$env[EnvConstants::DB_HOST]->string(), 'root',
            Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), $database, Hilos::$env[EnvConstants::DB_PORT]->int());
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        foreach (glob($root . '/Schema/*.sql') as $file) {
            foreach (MigrationSqlReader::statements(file_get_contents($file)) as $statement) {
                Database::sqlRun($statement);
            }
        }
    }

    /**
     * @param string $session Browser session token
     * @param int $userId Authenticated actor
     * @return list<string> Normal journal records for an authenticated browser
     */
    private function sessionLines(string $session, int $userId): array
    {
        return array_map(static fn(array $record): string => (string)AnalyticsJournalRecord::encode($record), [
            AnalyticsJournalRecord::wsConnectionOpen($session, null, 1000),
            AnalyticsJournalRecord::wsConnectionAttach($session, $session, null, null, 1000),
            AnalyticsJournalRecord::browserSessionIdentity($session, 'user_id', (string)$userId, 1000),
            AnalyticsJournalRecord::personEvent(new AnalyticsPersonEvent(
                $session, $userId, null, null, AnalyticsPersonEvent::SIGN_IN, null, null, null, null, 1000,
            )),
        ]);
    }

    /**
     * @param string $root Fixture FS root
     * @return list<string> Independent env, node and registered cluster sentinels
     */
    private function putSentinels(string $root): array
    {
        $paths = [];
        foreach (['journal/local/node/sentinel', 'journal/test/another-node/sentinel',
            'tmp/.hilos-cluster-directory.json', 'files/.hilos-cluster-directory.json',
            'exports/.hilos-cluster-directory.json'] as $relative) {
            $path = $root . '/' . $relative;
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, 'keep');
            $paths[] = $path;
        }
        return $paths;
    }
}

/** Test environment delegates every unrelated setting to the suite. */
final class ResetAnalyticsEnv extends EnvAccessor
{
    /**
     * @param EnvAccessor $source Suite environment to delegate to
     * @param string $database Disposable database name
     */
    public function __construct(private readonly EnvAccessor $source, private readonly string $database)
    {
        parent::__construct();
    }

    /**
     * @param string $key Catalog key
     * @return mixed Fixture override or suite value
     */
    public function effectiveValueFor(string $key): mixed
    {
        return match ($key) {
            EnvConstants::APP_ENV->name => 'test',
            EnvConstants::DB_DATABASE->name => $this->database,
            EnvConstants::DB_USERNAME->name => 'root',
            EnvConstants::DB_PASSWORD->name => $this->source[EnvConstants::DB_ROOT_PASSWORD]->string(),
            default => $this->source->effectiveValueFor($key),
        };
    }
}

class ResetAnalyticsHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];

    /** @var ?FsContext Factory result configured by the case */
    public static ?FsContext $filesystem = null;

    /** @return ?FsContext Case's registered filesystem, or null to reproduce missing FS */
    protected static function createFs(): ?FsContext
    {
        return self::$filesystem;
    }

    /** @return HilosDbContext Never initialized by the database-free reset */
    protected static function createDb(): HilosDbContext
    {
        throw new LogicException('The database-free reset must not initialize a data context');
    }
}

final class ResetWithoutAnalyticsHilos extends ResetAnalyticsHilos
{
    protected const array FEATURES = [];

    /** @return ?FsContext Never initialized without Analytics */
    protected static function createFs(): ?FsContext
    {
        throw new LogicException('A reset without Analytics must not initialize FS');
    }
}

final class ResetAnalyticsFsContext extends FsContext
{
    /**
     * @param string $root Fixture FS root
     * @param ?DirectoryScope $journalScope Journal owner, or null for missing registration
     */
    public function __construct(private readonly string $root, private readonly ?DirectoryScope $journalScope)
    {
    }

    /** Registers the fixture's journal and independent cluster directories. */
    public function configure(): void
    {
        if ($this->journalScope !== null) {
            $this->registerDirectory(self::ANALYTICS_JOURNAL, $this->root . '/journal', $this->journalScope);
        }
        $this->registerDirectory(self::FILES, $this->root . '/files', DirectoryScope::CLUSTER);
        $this->registerDirectory(self::DATA_EXPORT, $this->root . '/exports', DirectoryScope::CLUSTER);
        $this->setTmpPath($this->root . '/tmp', DirectoryScope::CLUSTER);
    }
}
