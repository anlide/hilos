<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\LogRootOwnerMarker;
use Hilos\Utils\Exception\LogRootOwnedByAnotherException;
use PHPUnit\Framework\TestCase;

/**
 * The durable claim that one daemon owns a log directory (HIL-1083).
 *
 * Held down here: a write is published whole, a missing file is not an owner, and a
 * file that is there but cannot be understood is foreign rather than absent — because
 * opening the directory on the strength of a parse failure would lose the claim.
 */
final class LogRootOwnerMarkerTest extends TestCase
{
    /** @var string Environment the asking process uses in these cases */
    private const string ENVIRONMENT = 'local';

    /** @var string Node id the asking process uses in these cases */
    private const string NODE = '';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-log-root-owner-' . uniqid('', true);
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

    public function testAPublishedMarkerReadsBackAsTheOwnerItWasWrittenWith(): void
    {
        LogRootOwnerMarker::publish($this->dir, self::ENVIRONMENT, self::NODE);

        $owner = LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);

        $this->assertNotNull($owner);
        $this->assertSame(self::ENVIRONMENT, $owner['environment']);
        $this->assertSame(self::NODE, $owner['node']);
        $this->assertIsInt($owner['startedAt']);
        $this->assertGreaterThan(0, $owner['startedAt']);
    }

    public function testADirectoryWithNoMarkerHasNoOwner(): void
    {
        $this->assertNull(LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE));
    }

    /**
     * The name is what keeps the marker out of every count and every weight: the store
     * walk globs `*.log`, so a directory of log files stays a directory of log files.
     */
    public function testTheMarkerIsNotALogFile(): void
    {
        LogRootOwnerMarker::publish($this->dir, self::ENVIRONMENT, self::NODE);

        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*.log'));
    }

    /**
     * The publish leaves nothing behind it: a temp file left in the log root would be
     * one more thing an operator has to judge.
     */
    public function testTheWriteLeavesNoTemporaryFileBehind(): void
    {
        LogRootOwnerMarker::publish($this->dir, self::ENVIRONMENT, self::NODE);

        $this->assertSame(
            [LogRootOwnerMarker::FILE_NAME],
            array_values(array_diff((array)scandir($this->dir), ['.', '..'])),
        );
    }

    public function testADamagedMarkerIsForeignNotMissing(): void
    {
        file_put_contents($this->markerPath(), 'not json at all');

        $this->expectException(LogRootOwnedByAnotherException::class);

        LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);
    }

    public function testAFileFromAFormatThisBuildDoesNotKnowIsForeign(): void
    {
        file_put_contents($this->markerPath(), (string)json_encode([
            'version' => 2,
            'environment' => self::ENVIRONMENT,
            'node' => self::NODE,
            'startedAt' => 1,
        ]));

        $this->expectException(LogRootOwnedByAnotherException::class);

        LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);
    }

    public function testAMarkerMissingAnOwnerFieldIsForeign(): void
    {
        file_put_contents($this->markerPath(), (string)json_encode([
            'version' => 1,
            'environment' => self::ENVIRONMENT,
            'node' => self::NODE,
        ]));

        $this->expectException(LogRootOwnedByAnotherException::class);

        LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);
    }

    /**
     * An unreadable marker names who was turned away, because the operator reading
     * `docker logs` has to tell this process from the owner the file would have named.
     */
    public function testAnUnreadableRefusalNamesTheArrivingProcess(): void
    {
        file_put_contents($this->markerPath(), 'not json at all');

        $message = '';
        try {
            LogRootOwnerMarker::read($this->dir, 'test', 'node-a');
            $this->fail('An unreadable marker was accepted as an owner');
        } catch (LogRootOwnedByAnotherException $refusal) {
            $message = $refusal->getMessage();
        }

        $this->assertStringContainsString('test', $message);
        $this->assertStringContainsString('node-a', $message);
        $this->assertStringContainsString($this->markerPath(), $message);
    }

    /**
     * @return string Absolute path of the marker inside the fixture directory
     */
    private function markerPath(): string
    {
        return LogRootOwnerMarker::pathIn($this->dir);
    }
}
