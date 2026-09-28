<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\AdminViewMode;

use Hilos\AdminViewMode\AdminViewModeLatchFile;
use PHPUnit\Framework\TestCase;

/**
 * The half of the admin view mode latch that lives in the log directory (HIL-1249).
 *
 * Held down here: a write is published whole, a missing file is no latch, and a file that is
 * there but cannot be understood is a latch all the same - opening the mode on the strength of a
 * parse failure would undo it.
 */
final class AdminViewModeLatchFileTest extends TestCase
{
    /** @var string Environment the asking process uses in these cases */
    private const string ENVIRONMENT = 'prod';

    /** @var string Node id the asking process uses in these cases */
    private const string NODE = 'node-a';

    /** @var int When the fixture latch was closed: far from now, so a read of it is not "now" */
    private const int CLOSED_AT = 1700000000;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-admin-view-mode-latch-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testAPublishedLatchReadsBackAsTheRecordItWasWrittenWith(): void
    {
        AdminViewModeLatchFile::publish($this->dir, self::ENVIRONMENT, self::NODE, self::CLOSED_AT);

        $this->assertSame(
            [
                AdminViewModeLatchFile::KEY_ENVIRONMENT => self::ENVIRONMENT,
                AdminViewModeLatchFile::KEY_NODE => self::NODE,
                AdminViewModeLatchFile::KEY_CLOSED_AT => self::CLOSED_AT,
            ],
            AdminViewModeLatchFile::read($this->dir, 'staging', 'node-b'),
        );
    }

    public function testADirectoryWithNoFileHasNoLatch(): void
    {
        $this->assertNull(AdminViewModeLatchFile::read($this->dir, self::ENVIRONMENT, self::NODE));
    }

    /**
     * The name is what keeps the latch out of the log walk and the rotation: both glob `*.log`,
     * so neither counts the file nor moves it away.
     */
    public function testTheLatchIsNotALogFile(): void
    {
        AdminViewModeLatchFile::publish($this->dir, self::ENVIRONMENT, self::NODE, self::CLOSED_AT);

        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*.log'));
    }

    /**
     * The publish leaves nothing behind it: a temp file left in the log root would be one more
     * thing an operator has to judge.
     */
    public function testTheWriteLeavesNoTemporaryFileBehind(): void
    {
        AdminViewModeLatchFile::publish($this->dir, self::ENVIRONMENT, self::NODE, self::CLOSED_AT);

        $this->assertSame(
            [AdminViewModeLatchFile::FILE_NAME],
            array_values(array_diff((array)scandir($this->dir), ['.', '..'])),
        );
    }

    public function testADamagedFileIsALatchNotMissing(): void
    {
        file_put_contents(AdminViewModeLatchFile::pathIn($this->dir), 'not json at all');

        $this->assertAskerClosedNow(AdminViewModeLatchFile::read($this->dir, self::ENVIRONMENT, self::NODE));
    }

    public function testAFileFromAFormatThisBuildDoesNotKnowIsALatch(): void
    {
        file_put_contents(AdminViewModeLatchFile::pathIn($this->dir), (string)json_encode([
            'version' => 2,
            'environment' => 'staging',
            'node' => 'node-b',
            'closedAt' => self::CLOSED_AT,
        ]));

        $this->assertAskerClosedNow(AdminViewModeLatchFile::read($this->dir, self::ENVIRONMENT, self::NODE));
    }

    public function testAFileMissingAFieldIsALatch(): void
    {
        file_put_contents(AdminViewModeLatchFile::pathIn($this->dir), (string)json_encode([
            'version' => 1,
            'environment' => 'staging',
        ]));

        $this->assertAskerClosedNow(AdminViewModeLatchFile::read($this->dir, self::ENVIRONMENT, self::NODE));
    }

    /**
     * An unreadable file comes back as the asking node closed now: the record the other half is
     * written back from when the row is gone.
     *
     * @param ?array{environment: string, node: string, closedAt: int} $latch What the read returned
     */
    private function assertAskerClosedNow(?array $latch): void
    {
        $this->assertNotNull($latch, 'A file that is there must close the mode, whatever it carries');
        $this->assertSame(self::ENVIRONMENT, $latch[AdminViewModeLatchFile::KEY_ENVIRONMENT]);
        $this->assertSame(self::NODE, $latch[AdminViewModeLatchFile::KEY_NODE]);
        $this->assertGreaterThan(self::CLOSED_AT, $latch[AdminViewModeLatchFile::KEY_CLOSED_AT]);
    }
}
