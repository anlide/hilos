<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseBinding;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\File;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Seed;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\ContentHash;
use Hilos\Files\HilosFiles;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use mysqli;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A seed's files reach the files storage through the offline reset, on a database of its own.
 *
 * The reset command never passes the full init, so every case starts with no files door: the
 * seed has to open it itself, and a case that must not touch the storage proves it by the door
 * still missing afterwards.
 */
final class SeedFilesIntegrationTest extends TestCase
{
    private const string SEED = '001_catalog';

    private EnvAccessor $previousEnv;
    private string $previousAppClass;
    /** @var array<int, DatabaseConnectionConfig> Connections to restore */
    private array $previousConfigurations;
    /** @var array<string, DatabaseBinding> Logical project registrations to restore */
    private array $previousDatabaseBindings;
    private ?int $previousTransactionIndex;
    /** @var list<int> Connections open on entry */
    private array $previousConnected;
    private int $previousIndex;
    /** @var array<string, mixed> Migration settings to restore */
    private array $previousMigration;
    private ?string $previousSeedPath;
    private ?FsContext $previousFs;
    private ?HilosFiles $previousFiles;
    private mysqli $server;
    private string $database;
    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousAppClass = Hilos::appClass();
        $this->previousConfigurations = new ReflectionProperty(Database::class, 'configurations')->getValue();
        $this->previousDatabaseBindings = new ReflectionProperty(Database::class, 'databaseBindings')->getValue();
        $this->previousTransactionIndex = new ReflectionProperty(Database::class, 'transactionIndex')->getValue();
        $this->previousConnected = array_values(array_filter(Database::getConfiguredIndices(), Database::isConnected(...)));
        $this->previousIndex = Database::getCurrentIndex();
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        $this->previousSeedPath = Seed::getSeedPath();
        $this->previousFs = Hilos::$fs;
        $this->previousFiles = Hilos::$files;
        $this->database = 'hilos-seed-files-' . bin2hex(random_bytes(6));
        $this->temporaryPath = sys_get_temp_dir() . '/' . $this->database;
        foreach (['Schema', 'seeds', 'tmp', 'files'] as $directory) {
            mkdir($this->temporaryPath . '/' . $directory, 0777, true);
        }
        file_put_contents($this->temporaryPath . '/Schema/001_file.sql', 'CREATE TABLE `' . File::_table . '` ('
            . '`' . File::id . '` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`' . File::stored_name . '` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE, '
            . '`' . File::size . '` BIGINT UNSIGNED NOT NULL, '
            . '`' . File::content_hash . '` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL);');
        $this->server = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            'root',
            Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            '',
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        foreach (Database::getConfiguredIndices() as $index) {
            Database::close($index);
            Schema::reset($index);
        }
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, []);
        new ReflectionProperty(Database::class, 'databaseBindings')->setValue(null, []);
        new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, null);
        new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
        Hilos::$env = new SeedFilesEnv($this->previousEnv, $this->database);
        Migration::setMigrationListPath($this->temporaryPath);
        Migration::setMigrationName('Schema');
        Seed::setSeedPath($this->temporaryPath . '/seeds');
        Hilos::$fs = new SeedFilesFsContext($this->temporaryPath . '/tmp', $this->temporaryPath . '/files');
        Hilos::$fs->configure();
        Hilos::$files = null;
    }

    protected function tearDown(): void
    {
        try {
            foreach (Database::getConfiguredIndices() as $index) {
                Database::close($index);
                Schema::reset($index);
            }
            if (isset($this->server)) {
                $this->server->query('DROP DATABASE IF EXISTS `' . $this->database . '`');
                $this->server->close();
            }
            self::removeTree($this->temporaryPath);
        } finally {
            Hilos::$files = $this->previousFiles;
            Hilos::$fs = $this->previousFs;
            Hilos::$env = $this->previousEnv;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Seed::class, 'seedPath')->setValue(null, $this->previousSeedPath);
            new ReflectionProperty(Database::class, 'configurations')->setValue(null, $this->previousConfigurations);
            new ReflectionProperty(Database::class, 'databaseBindings')->setValue(null, $this->previousDatabaseBindings);
            new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, $this->previousTransactionIndex);
            foreach ($this->previousConnected as $index) {
                Database::connect($index);
            }
            new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, $this->previousIndex);
            parent::tearDown();
        }
    }

    public function testTheResetPutsEveryCarriedFileIntoTheStorageByteForByteAndARepeatReplacesIt(): void
    {
        $rose = "\x89PNG\r\n\x1a\n\x00rose" . random_bytes(32);
        $tulip = "tulip\n";
        $this->writeSeed(self::SEED, ['rose.png' => $rose, 'tulip.txt' => $tulip]);
        file_put_contents($this->seedDirectory(self::SEED) . '/.keep', '');
        mkdir($this->seedDirectory(self::SEED) . '/nested');
        file_put_contents($this->seedDirectory(self::SEED) . '/nested/lily.txt', 'lily');
        file_put_contents($this->temporaryPath . '/outside.txt', 'outside');
        symlink($this->temporaryPath . '/outside.txt', $this->seedDirectory(self::SEED) . '/linked.txt');

        $this->resetWith(SeedFilesHilos::class);

        self::assertNotNull(Hilos::$files);
        self::assertSame(['rose.png', 'tulip.txt'], $this->stored());
        self::assertSame($rose, Hilos::$files->storage->read('rose.png'));
        self::assertSame($tulip, Hilos::$files->storage->read('tulip.txt'));
        self::assertSame([], $this->entries('tmp'));

        $repainted = "\x89PNG\r\n\x1a\n\x00repainted rose" . random_bytes(48);
        $this->writeSeed(self::SEED, ['rose.png' => $repainted, 'tulip.txt' => $tulip]);
        $this->resetWith(SeedFilesHilos::class);

        self::assertSame(['rose.png', 'tulip.txt'], $this->stored());
        self::assertSame($repainted, Hilos::$files->storage->read('rose.png'));
        self::assertSame([], $this->entries('tmp'));
    }

    public function testASeedWithoutADirectoryLeavesTheStorageAlone(): void
    {
        file_put_contents($this->temporaryPath . '/seeds/' . self::SEED . '.sql', self::rowsSql(['rose.png' => 'rose']));

        $this->resetWith(SeedFilesHilos::class);

        self::assertNull(Hilos::$files);
        self::assertSame([], $this->stored());
        Database::sql('SELECT COUNT(*) AS n FROM `' . File::_table . '`');
        self::assertSame(1, (int)Database::field('n'));
    }

    public function testAFileWithoutARowRefusesTheSeedBeforeTheNextFile(): void
    {
        $this->writeSeed(self::SEED, ['amaryllis.txt' => 'amaryllis', 'rose.png' => 'rose', 'tulip.txt' => 'tulip']);
        file_put_contents($this->seedFile(self::SEED), self::rowsSql(['amaryllis.txt' => 'amaryllis', 'tulip.txt' => 'tulip']));

        self::assertSame(
            'Seed 001_catalog carries rose.png, but no hilos_file row is stored under that name',
            $this->refusalWith(SeedFilesHilos::class)->getMessage(),
        );
        self::assertSame(['amaryllis.txt'], $this->stored());
    }

    public function testAFileOfAnotherSizeThanItsRowRefusesTheSeed(): void
    {
        $this->writeSeed(self::SEED, ['rose.png' => 'rose']);
        file_put_contents($this->seedFile(self::SEED), self::rowsSql(['rose.png' => 'roses']));

        self::assertSame(
            'Seed 001_catalog carries rose.png of 4 bytes, but its hilos_file row says 5',
            $this->refusalWith(SeedFilesHilos::class)->getMessage(),
        );
        self::assertSame([], $this->stored());
    }

    public function testAFileOfAnotherHashThanItsRowRefusesTheSeed(): void
    {
        $this->writeSeed(self::SEED, ['rose.png' => 'rose']);
        file_put_contents($this->seedFile(self::SEED), self::rowsSql(['rose.png' => 'lily']));

        self::assertSame(
            'Seed 001_catalog carries rose.png with sha256 618d663af0f1ec88a5a19defa65a2f80d06582a832510b12f475d80870bdb3ab'
                . ', but its hilos_file row says 40903c59d19feef1d67c455499304c194ebdec82df78790c3ceaac92bd1d84be',
            $this->refusalWith(SeedFilesHilos::class)->getMessage(),
        );
        self::assertSame([], $this->stored());
    }

    public function testADirectoryInAProjectWithoutFilesRefusesTheSeedBeforeItsFirstFile(): void
    {
        $this->writeSeed(self::SEED, ['rose.png' => 'rose']);

        self::assertSame(
            'Seed 001_catalog carries files, but the project does not declare FILES',
            $this->refusalWith(SeedFilesWithoutFilesHilos::class)->getMessage(),
        );
        self::assertNull(Hilos::$files);
        self::assertSame([], $this->stored());
    }

    public function testApplyingOneSeedPutsOnlyTheFilesOfThatSeed(): void
    {
        $this->resetWith(SeedFilesHilos::class);
        $this->writeSeed(self::SEED, ['rose.png' => 'rose']);
        $this->writeSeed('002_garden', ['lily.txt' => 'lily']);

        self::assertTrue(Seed::applyOne('002'));

        self::assertSame(['lily.txt'], $this->stored());
        Database::sql('SELECT `' . File::stored_name . '` FROM `' . File::_table . '`');
        self::assertSame(['lily.txt'], array_column(Database::rows(), File::stored_name));
    }

    /** @param class-string<Hilos> $facade Feature declaration for this CLI invocation */
    private function resetWith(string $facade): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $facade);
        // Each real CLI invocation begins with an empty migration initialization cache.
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        ob_start();
        try {
            self::assertSame(ExitCode::SUCCESS, (new DbTestResetCommand())->execute([], []));
        } finally {
            ob_end_clean();
        }
    }

    /** @param class-string<Hilos> $facade Feature declaration for this CLI invocation */
    private function refusalWith(string $facade): DatabaseException
    {
        try {
            $this->resetWith($facade);
        } catch (DatabaseException $refusal) {
            return $refusal;
        }

        self::fail('The reset applied a seed whose files had to be refused');
    }

    /**
     * Writes a seed whose SQL registers exactly the files it carries.
     *
     * @param array<string, string> $files Bytes by file name
     */
    private function writeSeed(string $seed, array $files): void
    {
        file_put_contents($this->seedFile($seed), self::rowsSql($files));
        if (!is_dir($this->seedDirectory($seed))) {
            mkdir($this->seedDirectory($seed));
        }
        foreach ($files as $name => $bytes) {
            file_put_contents($this->seedDirectory($seed) . '/' . $name, $bytes);
        }
    }

    /** @param array<string, string> $files Bytes the rows describe, by stored name */
    private static function rowsSql(array $files): string
    {
        $sql = '';
        foreach ($files as $name => $bytes) {
            $sql .= 'INSERT INTO `' . File::_table . '` (`' . File::stored_name . '`, `' . File::size . '`, `'
                . File::content_hash . "`) VALUES ('{$name}', " . strlen($bytes) . ", '"
                . hash(ContentHash::ALGORITHM, $bytes) . "');\n";
        }

        return $sql;
    }

    private function seedFile(string $seed): string
    {
        return $this->temporaryPath . '/seeds/' . $seed . '.sql';
    }

    private function seedDirectory(string $seed): string
    {
        return $this->temporaryPath . '/seeds/' . $seed;
    }

    /** @return list<string> Names the files storage holds */
    private function stored(): array
    {
        return $this->entries('files');
    }

    /** @return list<string> Entries of one of the case's directories, by name */
    private function entries(string $directory): array
    {
        $path = $this->temporaryPath . '/' . $directory;

        return is_dir($path) ? array_values(array_diff(scandir($path), ['.', '..'])) : [];
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

/** Env values for the case's own database; the suite's connection is only read. */
final class SeedFilesEnv extends EnvAccessor
{
    public function __construct(private readonly EnvAccessor $source, private readonly string $database)
    {
        parent::__construct();
    }

    /**
     * @param string $key Env key
     * @return mixed Fixture override or the suite value
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

class SeedFilesHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::FILES];

    /** @return HilosDbContext Unused facade data context */
    protected static function createDb(): HilosDbContext
    {
        return new SeedFilesDbContext();
    }
}

final class SeedFilesWithoutFilesHilos extends SeedFilesHilos
{
    protected const array FEATURES = [];
}

final class SeedFilesDbContext extends HilosDbContext
{
    /** The reset command does not initialize the facade's data context. */
    public function configure(): void
    {
    }
}

/** FS context with the tmp and files directories of the case. */
final class SeedFilesFsContext extends FsContext
{
    public function __construct(private readonly string $tmpPath, private readonly string $filesPath)
    {
    }

    /** Configures both directories. */
    public function configure(): void
    {
        $this->setTmpPath($this->tmpPath, DirectoryScope::CLUSTER);
        $this->registerDirectory(FsContext::FILES, $this->filesPath, DirectoryScope::CLUSTER);
    }
}
