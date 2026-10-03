<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\File;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\HilosException;
use ReflectionProperty;

/**
 * Integration coverage for the files registry: the janitor of unbound files and the bind frame (HIL-336),
 * and the remove frame that takes a file at once by the janitor's own steps (HIL-144).
 *
 * The janitor and the remove frame delete a file through the storage seam (HIL-136), so the case
 * puts the local storage on the door, over the files directory of the case.
 *
 * The selection, the row-then-file order and the foreign-key refusal are database behavior end to
 * end, so the cases use the real hilos_file table, a real directory, and the library tick
 * production runs. A file that refuses to be deleted cannot be staged by permissions - the suite
 * runs as root - so it is staged structurally: a files directory on procfs, whose entries
 * `unlink()` declines whoever asks.
 */
final class FileSweepTest extends FrameworkIntegrationTestCase
{
    /** Framework tables the cases raise, in dependency order. */
    private const array TABLES = [
        'hilos_user',
        'hilos_setting',
        'hilos_file',
        'hilos_file_variant',
    ];

    /** Project table whose foreign key holds a registry row, as a chat attachment does (HIL-144). */
    private const string LINK_TABLE = 'file_sweep_test_link';

    /** Owner of every fixture file. */
    private const int OWNER = 7;

    /** Hours past the default lifetime at which a fixture row counts as old. */
    private const int OLD_HOURS = FilesSettingsCatalog::DEFAULT_UNBOUND_TTL_HOURS + 1;

    /** A directory whose entries refuse unlink even to root. */
    private const string UNDELETABLE_DIRECTORY = '/proc';

    /** A regular-looking entry of that directory. */
    private const string UNDELETABLE_NAME = 'version';

    private ?DbContext $previousDb = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?FsContext $previousFs = null;

    private ?HilosFiles $previousFiles = null;

    private string $filesPath;

    private FileSweepTestAgent $agent;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the database context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Owner')", [self::OWNER]);
        Database::sqlRun(
            'CREATE TABLE `' . self::LINK_TABLE . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `file_id` INT UNSIGNED NOT NULL,'
            . ' PRIMARY KEY (`id`), CONSTRAINT `fk_file_sweep_test_link_file` FOREIGN KEY (`file_id`) REFERENCES `hilos_file` (`id`))',
        );
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousFs = Hilos::$fs;
        $this->previousFiles = Hilos::$files;
        Hilos::$db = new FileSweepTestDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(FilesSettingsCatalog::class);
        $this->filesPath = sys_get_temp_dir() . '/hilos-files-' . bin2hex(random_bytes(6));
        mkdir($this->filesPath);
        Hilos::$fs = new FileSweepTestFsContext($this->filesPath);
        Hilos::$fs->configure();
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        $this->agent = new FileSweepTestAgent();
        OwnershipDeclaration::claimAll($this->agent);
        $this->agent->onStart();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_FILES_LIBRARY);
        ExecutionContext::clear();
        SourceChangeBus::reset();

        foreach (glob($this->filesPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->filesPath);

        Hilos::$files = $this->previousFiles;
        Hilos::$fs = $this->previousFs;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    public function testAnOldUnboundFileLosesItsRowAndItsFile(): void
    {
        $old = $this->publish('old.bin', hoursAgo: self::OLD_HOURS);

        $this->runSweep();

        self::assertFalse(self::rowExists($old));
        self::assertFileDoesNotExist($this->filesPath . '/old.bin');
        self::assertContains('Files sweep: removed 1 unbound, marked 0 referenced', $this->agent->infos);
    }

    /** The library's own copies must not be mistaken for a project's link. */
    public function testAnOldUnboundFileLosesBothOfItsVariants(): void
    {
        $old = $this->publish('old.bin', hoursAgo: self::OLD_HOURS);
        $other = $this->publish('young.bin', hoursAgo: 0);
        $this->keepVariant($old, 'thumb', 'old-thumb.webp');
        $this->keepVariant($old, 'preview', 'old-preview.webp');
        $this->keepVariant($other, 'thumb', 'young-thumb.webp');
        $copies = Hilos::$db->fileVariants->forFile($old);
        self::assertCount(2, $copies);

        $this->runSweep();

        self::assertFalse(self::rowExists($old));
        self::assertSame([], Hilos::$db->fileVariants->forFile($old));
        foreach ($copies as $copy) {
            self::assertNull(Hilos::$db->fileVariants[$copy->id], 'Deleted copies leave the item cache too');
        }
        self::assertFileDoesNotExist($this->filesPath . '/old.bin');
        self::assertFileDoesNotExist($this->filesPath . '/old-thumb.webp');
        self::assertFileDoesNotExist($this->filesPath . '/old-preview.webp');
        self::assertCount(1, Hilos::$db->fileVariants->forFile($other));
        self::assertFileExists($this->filesPath . '/young-thumb.webp');
        self::assertSame([], $this->agent->warnings);
    }

    /** A project link keeps the original; the removed copy rows must leave no stray bytes. */
    public function testAReferencedFileKeepsItsOriginalAndLosesItsVariants(): void
    {
        $linked = $this->publish('linked.bin', hoursAgo: self::OLD_HOURS);
        $this->keepVariant($linked, 'thumb', 'linked-thumb.webp');
        $this->keepVariant($linked, 'preview', 'linked-preview.webp');
        Database::sqlRun('INSERT INTO `' . self::LINK_TABLE . '` (`file_id`) VALUES (?)', [$linked]);

        $this->runSweep();

        self::assertTrue(self::rowExists($linked));
        self::assertSame(1, self::boundFlag($linked));
        self::assertSame([], Hilos::$db->fileVariants->forFile($linked));
        self::assertFileExists($this->filesPath . '/linked.bin');
        self::assertFileDoesNotExist($this->filesPath . '/linked-thumb.webp');
        self::assertFileDoesNotExist($this->filesPath . '/linked-preview.webp');
        self::assertContains("File {$linked} is referenced by a project row; marked bound", $this->agent->warnings);
    }

    /** Replacing a rendering keeps the same row key and updates the values read after reloading. */
    public function testAVariantCanBeReplacedAndReadByItsFileAndName(): void
    {
        $fileId = $this->publish('original.bin', hoursAgo: 0);
        $this->keepVariant($fileId, 'thumb', 'thumb.webp');
        $variant = Hilos::$db->fileVariants->findFor($fileId, 'thumb');
        self::assertNotNull($variant);
        $id = $variant->id;

        $variant->actions->replace('abcdef12', 'new-thumb.png', 'image/png', 42);

        Hilos::$db = new FileSweepTestDbContext();
        Hilos::$db->configure();
        $reloaded = Hilos::$db->fileVariants->findFor($fileId, 'thumb');
        self::assertNotNull($reloaded);
        self::assertSame($id, $reloaded->id);
        self::assertSame('abcdef12', $reloaded->signature);
        self::assertSame('new-thumb.png', $reloaded->storedName);
        self::assertSame('image/png', $reloaded->mimeType);
        self::assertSame(42, $reloaded->size);
        self::assertNull(Hilos::$db->fileVariants->findFor($fileId, 'absent'));
        self::assertSame(43, Hilos::$db->files->totalSize());
    }

    /** A refused replacement must not leave the cached item pointing at bytes the caller will discard. */
    public function testARefusedReplacementKeepsTheStoredCopyInMemory(): void
    {
        $fileId = $this->publish('original.bin', hoursAgo: 0);
        $this->keepVariant($fileId, 'thumb', 'thumb.webp');
        $this->keepVariant($fileId, 'preview', 'preview.webp');
        $variant = Hilos::$db->fileVariants->findFor($fileId, 'thumb');
        self::assertNotNull($variant);

        try {
            $variant->actions->replace('abcdef12', 'preview.webp', 'image/png', 42);
            self::fail('Two rows cannot claim the same stored name');
        } catch (DuplicateEntryException) {
            self::assertSame('thumb.webp', $variant->storedName);
            self::assertSame('1234abcd', $variant->signature);
            self::assertSame('image/webp', $variant->mimeType);
            self::assertSame(4, $variant->size);
        }
        self::assertSame(9, Hilos::$db->files->totalSize());

        $variant->actions->replace('abcdef12', 'new-thumb.png', 'image/png', 42);
        self::assertSame('new-thumb.png', Hilos::$db->fileVariants->findFor($fileId, 'thumb')?->storedName);
        self::assertSame(47, Hilos::$db->files->totalSize());
    }

    public function testAYoungUnboundFileAndAnOldBoundFileStay(): void
    {
        $young = $this->publish('young.bin', hoursAgo: 0);
        $bound = $this->publish('bound.bin', hoursAgo: self::OLD_HOURS);
        Hilos::$db->files[$bound]?->actions->markBound();

        $this->runSweep();

        self::assertTrue(self::rowExists($young));
        self::assertTrue(self::rowExists($bound));
        self::assertFileExists($this->filesPath . '/young.bin');
        self::assertFileExists($this->filesPath . '/bound.bin');
        self::assertSame([], $this->agent->infos);
    }

    public function testALifetimeOfZeroSwitchesTheJanitorOff(): void
    {
        Hilos::$setting = new SettingsAccessor(FileSweepDisabledSettingsCatalog::class);
        $old = $this->publish('old.bin', hoursAgo: self::OLD_HOURS);

        $this->runSweep();

        self::assertTrue(self::rowExists($old));
        self::assertFileExists($this->filesPath . '/old.bin');
    }

    public function testAFullBatchContinuesOnTheNextTick(): void
    {
        for ($i = 0; $i <= AbstractFilesLibraryAgent::FILES_SWEEP_BATCH; $i++) {
            $this->publish("batch-{$i}.bin", hoursAgo: self::OLD_HOURS);
        }

        $this->runSweep();
        self::assertSame(1, self::rowCount());

        $this->agent->onTick();
        self::assertSame(0, self::rowCount());
        self::assertSame([], glob($this->filesPath . '/*'));
    }

    public function testARowWhoseFileIsGoneIsRemovedWithoutAnError(): void
    {
        $gone = $this->publish('gone.bin', hoursAgo: self::OLD_HOURS);
        unlink($this->filesPath . '/gone.bin');

        $this->runSweep();

        self::assertFalse(self::rowExists($gone));
        self::assertSame([], $this->agent->errors);
    }

    public function testAFileThatStaysOnDiskIsReportedAndItsRowIsNotBroughtBack(): void
    {
        Hilos::$fs = new FileSweepTestFsContext(self::UNDELETABLE_DIRECTORY);
        Hilos::$fs->configure();
        $stuck = $this->register(self::UNDELETABLE_NAME, hoursAgo: self::OLD_HOURS);

        $this->runSweep();

        self::assertFalse(self::rowExists($stuck));
        self::assertCount(1, $this->agent->errors);
        self::assertStringStartsWith('Orphan file ' . self::UNDELETABLE_NAME . ' left on disk: ', $this->agent->errors[0]);
    }

    /** A stuck copy is reported after its row goes, and does not prevent the original's cleanup. */
    public function testAVariantThatStaysOnDiskIsReportedAsAnOrphan(): void
    {
        $old = $this->register('absent-original.bin', hoursAgo: self::OLD_HOURS);
        Hilos::$db->fileVariants->actions->create($old, 'thumb', '1234abcd', self::UNDELETABLE_NAME, 'image/webp', 1);
        Hilos::$fs = new FileSweepTestFsContext(self::UNDELETABLE_DIRECTORY);
        Hilos::$fs->configure();

        $this->runSweep();

        self::assertFalse(self::rowExists($old));
        self::assertSame([], Hilos::$db->fileVariants->forFile($old));
        self::assertCount(1, $this->agent->errors);
        self::assertStringStartsWith('Orphan file ' . self::UNDELETABLE_NAME . ' left on disk: ', $this->agent->errors[0]);
    }

    public function testARowAProjectRowReferencesIsMarkedBoundAndKeepsItsFile(): void
    {
        $linked = $this->publish('linked.bin', hoursAgo: self::OLD_HOURS);
        Database::sqlRun('INSERT INTO `' . self::LINK_TABLE . '` (`file_id`) VALUES (?)', [$linked]);

        $this->runSweep();

        self::assertTrue(self::rowExists($linked));
        self::assertSame(1, self::boundFlag($linked));
        self::assertFileExists($this->filesPath . '/linked.bin');
        self::assertContains("File {$linked} is referenced by a project row; marked bound", $this->agent->warnings);
        self::assertContains('Files sweep: removed 0 unbound, marked 1 referenced', $this->agent->infos);
    }

    public function testTheBindFrameMarksRowsBoundAndSkipsAnUnknownId(): void
    {
        $first = $this->publish('first.bin', hoursAgo: 0);
        $second = $this->publish('second.bin', hoursAgo: 0);
        $unknown = $second + 1000;

        $this->bind([$first, $unknown, $second]);

        self::assertSame(1, self::boundFlag($first));
        self::assertSame(1, self::boundFlag($second));
        self::assertSame(["File {$unknown} is not in the registry"], $this->agent->warnings);
    }

    /**
     * A repeated bind writes nothing: a row changed underneath keeps what the database holds.
     */
    public function testARepeatedBindWritesNothing(): void
    {
        $file = $this->publish('again.bin', hoursAgo: 0);
        $this->bind([$file]);
        Database::sqlRun('UPDATE `hilos_file` SET `bound` = 0 WHERE `id` = ?', [$file]);

        $this->bind([$file]);

        self::assertSame(0, self::boundFlag($file));
    }

    /** A bound file is not the janitor's, but the remove frame takes it with both copies at once. */
    public function testTheRemoveFrameTakesABoundFileWithItsVariantsAtOnce(): void
    {
        $bound = $this->publish('bound.bin', hoursAgo: 0);
        $other = $this->publish('other.bin', hoursAgo: 0);
        Hilos::$db->files[$bound]?->actions->markBound();
        $this->keepVariant($bound, 'thumb', 'bound-thumb.webp');
        $this->keepVariant($bound, 'preview', 'bound-preview.webp');

        $this->remove([$bound]);

        self::assertFalse(self::rowExists($bound));
        self::assertSame([], Hilos::$db->fileVariants->forFile($bound));
        self::assertFileDoesNotExist($this->filesPath . '/bound.bin');
        self::assertFileDoesNotExist($this->filesPath . '/bound-thumb.webp');
        self::assertFileDoesNotExist($this->filesPath . '/bound-preview.webp');
        self::assertTrue(self::rowExists($other));
        self::assertFileExists($this->filesPath . '/other.bin');
        self::assertSame([], $this->agent->warnings);
        self::assertSame(['Files removed: 1, kept 0'], $this->agent->infos);
    }

    /** A project row still pointing at the file keeps the row as it was; its copies go regardless. */
    public function testTheRemoveFrameKeepsARowAProjectRowStillLinks(): void
    {
        $linked = $this->publish('linked.bin', hoursAgo: 0);
        $this->keepVariant($linked, 'thumb', 'linked-thumb.webp');
        Database::sqlRun('INSERT INTO `' . self::LINK_TABLE . '` (`file_id`) VALUES (?)', [$linked]);

        $this->remove([$linked]);

        self::assertTrue(self::rowExists($linked));
        self::assertSame(0, self::boundFlag($linked), 'The door does not mark the row; it leaves it as it was');
        self::assertFileExists($this->filesPath . '/linked.bin');
        self::assertSame([], Hilos::$db->fileVariants->forFile($linked));
        self::assertFileDoesNotExist($this->filesPath . '/linked-thumb.webp');
        self::assertSame(["File {$linked} is still linked by a project row; kept"], $this->agent->warnings);
        self::assertSame(['Files removed: 0, kept 1'], $this->agent->infos);
    }

    public function testTheRemoveFrameSkipsAnUnknownId(): void
    {
        $file = $this->publish('known.bin', hoursAgo: 0);
        $unknown = $file + 1000;

        $this->remove([$unknown, $file]);

        self::assertFalse(self::rowExists($file));
        self::assertFileDoesNotExist($this->filesPath . '/known.bin');
        self::assertSame(["File {$unknown} is not in the registry"], $this->agent->warnings);
        self::assertSame(['Files removed: 1, kept 0'], $this->agent->infos);
    }

    /**
     * @param int $fileId Original registry file id
     * @param string $variant Variant name
     * @param string $storedName Copy's name in storage
     * @throws HilosException When the copy cannot be registered
     */
    private function keepVariant(int $fileId, string $variant, string $storedName): void
    {
        file_put_contents($this->filesPath . '/' . $storedName, 'copy');
        Hilos::$db->fileVariants->actions->create($fileId, $variant, '1234abcd', $storedName, 'image/webp', 4);
    }

    /**
     * Registers a file and writes it into the files directory.
     *
     * @param string $storedName Name on disk
     * @param int $hoursAgo How long ago the row was registered
     * @return int Row id
     * @throws HilosException When the row cannot be written
     */
    private function publish(string $storedName, int $hoursAgo): int
    {
        file_put_contents($this->filesPath . '/' . $storedName, 'x');

        return $this->register($storedName, $hoursAgo);
    }

    /**
     * Registers a row without writing any file.
     *
     * @param string $storedName Name on disk
     * @param int $hoursAgo How long ago the row was registered
     * @return int Row id
     * @throws HilosException When the row cannot be written
     */
    private function register(string $storedName, int $hoursAgo): int
    {
        $file = Hilos::$db->files->actions->create(
            $storedName,
            'Original ' . $storedName,
            'application/octet-stream',
            1,
            hash('sha256', $storedName),
            self::OWNER,
            FileVisibility::AUTHENTICATED,
        );
        self::assertInstanceOf(File::class, $file);
        $id = $file->id;
        self::assertIsInt($id);
        Database::sqlRun(
            'UPDATE `hilos_file` SET `created_at` = ? WHERE `id` = ?',
            [date('Y-m-d H:i:s', time() - $hoursAgo * 3600), $id],
        );

        return $id;
    }

    /**
     * Runs the sweep now by raising the same backlog flag a full prior batch raises.
     *
     * @throws HilosException When the library tick fails
     */
    private function runSweep(): void
    {
        // Reflection raises the private backlog flag; the schedule alone fires at most once in fifteen minutes.
        new ReflectionProperty(AbstractFilesLibraryAgent::class, 'filesSweepBacklog')->setValue($this->agent, true);
        $this->agent->onTick();
    }

    /**
     * Delivers one bind frame to the library.
     *
     * @param list<int> $fileIds Ids the project linked
     * @throws HilosException When the library cannot handle the frame
     */
    private function bind(array $fileIds): void
    {
        $this->agent->onSignalAgent(
            new AgentSignalData(new FileBindSignalData($fileIds)),
            '',
            HilosSignalConstants::HILOS_FILE_BIND,
        );
    }

    /**
     * Delivers one remove frame to the library.
     *
     * @param list<int> $fileIds Ids the project no longer links
     * @throws HilosException When the library cannot handle the frame
     */
    private function remove(array $fileIds): void
    {
        $this->agent->onSignalAgent(
            new AgentSignalData(new FileRemoveSignalData($fileIds)),
            '',
            HilosSignalConstants::HILOS_FILE_REMOVE,
        );
    }

    /**
     * @param int $id Row id
     * @return bool Whether the row is in the table
     * @throws DatabaseException When the query fails
     */
    private static function rowExists(int $id): bool
    {
        return Database::sql('SELECT `id` FROM `hilos_file` WHERE `id` = ?', [$id])->firstRow() !== null;
    }

    /**
     * @param int $id Row id
     * @return int The bound flag the database holds
     * @throws DatabaseException When the query fails
     */
    private static function boundFlag(int $id): int
    {
        return (int)(Database::sql('SELECT `bound` FROM `hilos_file` WHERE `id` = ?', [$id])->firstRow()['bound'] ?? -1);
    }

    /**
     * @return int Rows in the table
     * @throws DatabaseException When the query fails
     */
    private static function rowCount(): int
    {
        return (int)(Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_file`')->firstRow()['count'] ?? -1);
    }

    /**
     * Creates or drops the tables the cases read.
     *
     * @param bool $down Whether to run the teardown half of each stub
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        if ($down) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . self::LINK_TABLE . '`');
        }
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $tables = $down ? array_reverse(self::TABLES) : self::TABLES;
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * Files library whose framework behavior is under test; it keeps what it logs.
 */
final class FileSweepTestAgent extends AbstractFilesLibraryAgent
{
    /** @var list<string> Info lines */
    public array $infos = [];

    /** @var list<string> Warning lines */
    public array $warnings = [];

    /** @var list<string> Error lines */
    public array $errors = [];

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentInfo(string $message): void
    {
        $this->infos[] = $message;
    }

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Settings catalog whose unbound lifetime is zero: the janitor is off.
 */
final class FileSweepDisabledSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> The lifetime key with a zero default
     */
    public static function getCatalog(): array
    {
        return [
            FilesSettingsCatalog::UNBOUND_TTL_HOURS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
        ];
    }
}

/**
 * DB context of the case: the framework collections and nothing of a project's.
 */
final class FileSweepTestDbContext extends HilosDbContext
{
}

/**
 * FS context naming the files directory of the case.
 */
final class FileSweepTestFsContext extends FsContext
{
    /**
     * @param string $filesPath Files directory of the case
     */
    public function __construct(private readonly string $filesPath)
    {
    }

    /**
     * Registers the files directory.
     */
    public function configure(): void
    {
        $this->registerDirectory(FsContext::FILES, $this->filesPath, DirectoryScope::CLUSTER);
    }
}
