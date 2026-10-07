<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\ProcessMetricsReader;
use PHPUnit\Framework\TestCase;

/** Kernel stat parsing preserves absence and rejects a reused process id. */
final class ProcessMetricsReaderTest extends TestCase
{
    public function testParsesRssWithSpacesAndParenthesesInComm(): void
    {
        self::assertSame(12 * 4096, ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'a ) b ( c', '12'), 4096));
        self::assertSame(0, ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'idle', '0'), 4096));
    }

    public function testRejectsPidReuseTruncationAndOverflow(): void
    {
        self::assertNull(ProcessMetricsReader::parseResidentBytes(124, self::stat(123, 'old', '12'), 4096));
        self::assertNull(ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'replacement', '12', '55'), 4096, 54));
        self::assertSame(12 * 4096, ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'same', '12', '55'), 4096, 55));
        self::assertNull(ProcessMetricsReader::parseResidentBytes(123, '123 (cut) S 1', 4096));
        self::assertNull(ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'large', (string)PHP_INT_MAX), 4096));
        self::assertNull(ProcessMetricsReader::parseResidentBytes(123, self::stat(123, 'bad', '-1'), 4096));
    }

    public function testMissingProcEntryIsUnknown(): void
    {
        self::assertNull((new ProcessMetricsReader())->readResidentBytes(PHP_INT_MAX));
    }

    public function testCurrentProcessSampleHasAStableBirthTick(): void
    {
        $reader = new ProcessMetricsReader();
        $pid = getmypid();
        $birth = $reader->readStartTimeTicks($pid);
        self::assertNotNull($birth);
        self::assertGreaterThan(0, $reader->readResidentBytes($pid, $birth));
        self::assertNull($reader->readResidentBytes($pid, $birth + 1));
    }

    /**
     * @param int $pid Process id in the stat row
     * @param string $comm Command name in parentheses
     * @param string $rss Resident pages
     * @param string $startTicks Kernel start tick
     * @return string Synthetic stat row through field 24
     */
    private static function stat(int $pid, string $comm, string $rss, string $startTicks = '55'): string
    {
        $fields = array_fill(0, 22, '0');
        $fields[0] = 'S';
        $fields[19] = $startTicks;
        $fields[21] = $rss;
        return $pid . ' (' . $comm . ') ' . implode(' ', $fields);
    }
}
