<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Fs\Exception\FileWriteException;
use Hilos\Log\LogBatchTakeoutMarker;
use PHPUnit\Framework\TestCase;

/**
 * The durable half of the takeout confirmation: the marker file inside a batch directory (HIL-483).
 *
 * What is held down here is that the fact survives everything the screen above it does not control.
 * It is written whole or not at all, it reads back as the stamp that was written, and a directory
 * that never had one, or has a damaged one, answers "not confirmed" rather than raising — because
 * this read runs for every batch of every walk, and one unreadable directory must not cost the
 * node its whole index.
 */
final class LogBatchTakeoutMarkerTest extends TestCase
{
    /** @var int Stamp the confirmations under test are recorded at */
    private const int TAKEN_AT = 1756166400;

    /** @var int User id the confirmations under test are attributed to */
    private const int TAKEN_BY = 42;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-takeout-marker-' . uniqid('', true);
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

    public function testAWrittenMarkerReadsBackAsTheStampItWasWrittenWith(): void
    {
        LogBatchTakeoutMarker::write($this->dir, self::TAKEN_AT, self::TAKEN_BY);

        $this->assertSame(self::TAKEN_AT, LogBatchTakeoutMarker::read($this->dir));
    }

    public function testABatchWithNoMarkerIsNotConfirmed(): void
    {
        $this->assertNull(LogBatchTakeoutMarker::read($this->dir));
    }

    /**
     * The confirmation names who made it, because "the batch was taken" and "this administrator
     * says they took it" are different facts and only the second one can be asked about later.
     */
    public function testTheMarkerNamesWhoConfirmed(): void
    {
        LogBatchTakeoutMarker::write($this->dir, self::TAKEN_AT, self::TAKEN_BY);

        $this->assertSame(
            [LogBatchTakeoutMarker::takenAt => self::TAKEN_AT, LogBatchTakeoutMarker::takenBy => self::TAKEN_BY],
            json_decode((string)file_get_contents($this->markerPath()), true),
        );
    }

    /**
     * A confirmation from a connection carrying no user is still a confirmation: the fact is about
     * the directory, and an unnamed operator is not a reason to refuse to record that it is gone.
     */
    public function testAConfirmationWithNobodyBehindItStillRecordsTheStamp(): void
    {
        LogBatchTakeoutMarker::write($this->dir, self::TAKEN_AT, null);

        $this->assertSame(self::TAKEN_AT, LogBatchTakeoutMarker::read($this->dir));
        $this->assertNull(json_decode((string)file_get_contents($this->markerPath()), true)[LogBatchTakeoutMarker::takenBy]);
    }

    /**
     * The name is what keeps the marker out of every count and every weight the screen shows: the
     * store walk globs `*.log`, so a batch of eleven files stays a batch of eleven files.
     */
    public function testTheMarkerIsNotALogFile(): void
    {
        LogBatchTakeoutMarker::write($this->dir, self::TAKEN_AT, self::TAKEN_BY);

        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*.log'));
    }

    /**
     * The publish leaves nothing behind it: a temp file left in the batch would be counted by
     * nobody but would still be a file an operator finds in a directory they are about to delete.
     */
    public function testTheWriteLeavesNoTemporaryFileBehind(): void
    {
        LogBatchTakeoutMarker::write($this->dir, self::TAKEN_AT, self::TAKEN_BY);

        $this->assertSame(
            [LogBatchTakeoutMarker::FILE_NAME],
            array_values(array_diff((array)scandir($this->dir), ['.', '..'])),
        );
    }

    /**
     * A marker that is not JSON at all answers the way a missing one does. It runs on every walk,
     * so raising here would trade a damaged file for a node with no index; and the next
     * confirmation writes the file whole, which repairs it.
     */
    public function testADamagedMarkerReadsAsNotConfirmed(): void
    {
        file_put_contents($this->markerPath(), 'not json at all');

        $this->assertNull(LogBatchTakeoutMarker::read($this->dir));
    }

    /**
     * Same for a marker that IS JSON but says nothing about when: an object without the stamp
     * cannot be believed into having one.
     */
    public function testAMarkerWithoutAStampReadsAsNotConfirmed(): void
    {
        file_put_contents($this->markerPath(), json_encode([LogBatchTakeoutMarker::takenBy => self::TAKEN_BY]));

        $this->assertNull(LogBatchTakeoutMarker::read($this->dir));
    }

    /**
     * A directory that is not there is the batch a cleanup carried away, and it is the caller's
     * business to tell that apart - the read only says there is no confirmation to be had.
     */
    public function testAMissingDirectoryReadsAsNotConfirmed(): void
    {
        $this->assertNull(LogBatchTakeoutMarker::read($this->dir . DIRECTORY_SEPARATOR . 'gone'));
    }

    /**
     * A write into a directory that is not there fails loudly, which is what lets the node answer
     * the person waiting instead of reporting a success that wrote nothing.
     */
    public function testAWriteIntoAMissingDirectoryFails(): void
    {
        $this->expectException(FileWriteException::class);

        LogBatchTakeoutMarker::write($this->dir . DIRECTORY_SEPARATOR . 'gone', self::TAKEN_AT, self::TAKEN_BY);
    }

    /**
     * @return string Absolute path of the marker inside the fixture directory
     */
    private function markerPath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . LogBatchTakeoutMarker::FILE_NAME;
    }
}
