<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Exception\InvalidFormatException;

/** Reads a process's kernel counters and resident bytes without starting a sampler. */
final class ProcessMetricsReader
{
    private const int BYTES_PER_KIB = 1024;
    private const int UTIME_FIELD_AFTER_COMM = 11;
    private const int STIME_FIELD_AFTER_COMM = 12;
    private const int START_TICKS_FIELD_AFTER_COMM = 19;
    private const int RSS_FIELD_AFTER_COMM = 21;
    private const int PAGE_SIZE_SAMPLE_BYTES = 8192;

    private readonly ?int $pageSizeBytes;
    private readonly ?int $ticksPerSecond;

    /**
     * @param string $procRoot Procfs root, replaceable by tests
     * @param ?int $ticksPerSecond Explicit clock frequency for tests, or null to detect it
     */
    public function __construct(private readonly string $procRoot = '/proc', ?int $ticksPerSecond = null)
    {
        $this->pageSizeBytes = $this->detectPageSizeBytes();
        $detected = $ticksPerSecond;
        if ($detected === null && defined('POSIX_SC_CLK_TCK') && function_exists('posix_sysconf')) {
            $value = posix_sysconf(constant('POSIX_SC_CLK_TCK'));
            $detected = is_int($value) ? $value : null;
        }
        $this->ticksPerSecond = $detected !== null && $detected > 0 ? $detected : null;
    }

    /** @return ?int Kernel clock ticks per second, or null when unavailable */
    public function ticksPerSecond(): ?int
    {
        return $this->ticksPerSecond;
    }

    /** @return ?int Kernel page size in bytes, or null when unavailable */
    public function pageSizeBytes(): ?int
    {
        return $this->pageSizeBytes;
    }

    /**
     * @param int $pid Process id to inspect
     * @return ?ProcessSample One process sample, or null when its stat row is unavailable
     * @throws InvalidFormatException When the parsed sample violates its value contract
     */
    public function readSample(int $pid): ?ProcessSample
    {
        $stat = $this->readStat($pid);
        return $stat === null ? null : self::parseSample($pid, $stat, $this->pageSizeBytes);
    }

    /**
     * @param int $pid Expected process id
     * @param string $stat Kernel stat row
     * @param ?int $pageSizeBytes Validated page size, or null when unknown
     * @return ?ProcessSample Parsed sample, or null when identity or CPU counters are invalid
     * @throws InvalidFormatException When the parsed sample violates its value contract
     */
    public static function parseSample(int $pid, string $stat, ?int $pageSizeBytes): ?ProcessSample
    {
        $fields = self::fieldsAfterComm($pid, $stat);
        if ($fields === null) {
            return null;
        }
        $start = self::unsignedInt($fields[self::START_TICKS_FIELD_AFTER_COMM] ?? null);
        $utime = self::unsignedInt($fields[self::UTIME_FIELD_AFTER_COMM] ?? null);
        $stime = self::unsignedInt($fields[self::STIME_FIELD_AFTER_COMM] ?? null);
        if ($start === null || $utime === null || $stime === null || $utime > PHP_INT_MAX - $stime) {
            return null;
        }
        $pages = self::unsignedInt($fields[self::RSS_FIELD_AFTER_COMM] ?? null);
        $resident = $pageSizeBytes !== null && $pageSizeBytes > 0 && $pages !== null
            && $pages <= intdiv(PHP_INT_MAX, $pageSizeBytes) ? $pages * $pageSizeBytes : null;
        return new ProcessSample($start, $utime + $stime, $resident);
    }

    /**
     * @param int $pid Process id to inspect
     * @return ?int Kernel start tick, or null when the process is unavailable
     */
    public function readStartTimeTicks(int $pid): ?int
    {
        $stat = $this->readStat($pid);
        return $stat === null ? null : self::parseStartTimeTicks($pid, $stat);
    }

    /**
     * @param int $pid Process id to inspect
     * @param ?int $expectedStartTicks Earlier kernel start tick, when guarding against PID reuse
     * @return ?int RSS in bytes, or null when the process or page size is unavailable
     */
    public function readResidentBytes(int $pid, ?int $expectedStartTicks = null): ?int
    {
        if ($this->pageSizeBytes === null) {
            return null;
        }
        $stat = $this->readStat($pid);
        return $stat === null ? null : self::parseResidentBytes($pid, $stat, $this->pageSizeBytes, $expectedStartTicks);
    }

    /**
     * @param int $pid Expected process id, checked against the stat row to reject a stale or reused id
     * @param string $stat Kernel stat row
     * @param int $pageSizeBytes Validated kernel page size
     * @param ?int $expectedStartTicks Earlier start tick, when checking PID reuse
     * @return ?int RSS in bytes, or null when the row is truncated, mismatched, or overflows
     */
    public static function parseResidentBytes(int $pid, string $stat, int $pageSizeBytes, ?int $expectedStartTicks = null): ?int
    {
        if ($pageSizeBytes <= 0) {
            return null;
        }
        $fields = self::fieldsAfterComm($pid, $stat);
        if ($fields === null) {
            return null;
        }
        if ($expectedStartTicks !== null && self::unsignedInt($fields[self::START_TICKS_FIELD_AFTER_COMM] ?? null) !== $expectedStartTicks) {
            return null;
        }
        $pagesText = $fields[self::RSS_FIELD_AFTER_COMM] ?? null;
        $pages = self::unsignedInt($pagesText);
        if ($pages === null || $pages > intdiv(PHP_INT_MAX, $pageSizeBytes)) {
            return null;
        }

        return $pages * $pageSizeBytes;
    }

    /**
     * @param int $pid Expected process id
     * @param string $stat Kernel stat row
     * @return ?int Kernel start tick, or null when the row is invalid
     */
    public static function parseStartTimeTicks(int $pid, string $stat): ?int
    {
        $fields = self::fieldsAfterComm($pid, $stat);
        return $fields === null ? null : self::unsignedInt($fields[self::START_TICKS_FIELD_AFTER_COMM] ?? null);
    }

    /**
     * @param int $pid Process id to inspect
     * @return ?string Kernel stat row, or null when unavailable
     */
    private function readStat(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }
        // warning-suppressed: /proc can disappear between roster and sample; null preserves unknown metrics
        $stat = @file_get_contents("{$this->procRoot}/{$pid}/stat");
        return $stat === false ? null : $stat;
    }

    /**
     * @param int $pid Expected process id
     * @param string $stat Kernel stat row
     * @return ?list<string> Fields after the parenthesized comm value
     */
    private static function fieldsAfterComm(int $pid, string $stat): ?array
    {
        if ($pid <= 0) {
            return null;
        }
        $open = strpos($stat, '(');
        $close = strrpos($stat, ')');
        if ($open === false || $close === false || $close <= $open || trim(substr($stat, 0, $open)) !== (string)$pid) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        return $fields === false ? null : $fields;
    }

    /**
     * @param mixed $value Decimal field from a stat row
     * @return ?int Nonnegative integer that fits PHP, or null
     */
    private static function unsignedInt(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $number = (int)$value;
        return (string)$number === $value ? $number : null;
    }

    /** @return ?int Kernel page size in bytes, sampled once before the runtime loop */
    private function detectPageSizeBytes(): ?int
    {
        // warning-suppressed: /proc may be absent off Linux; no page size means all RSS samples stay unknown
        $smaps = @file_get_contents("{$this->procRoot}/self/smaps", false, null, 0, self::PAGE_SIZE_SAMPLE_BYTES);
        if ($smaps === false || preg_match('/^KernelPageSize:\s+(\d+)\s+kB$/m', $smaps, $match) !== 1) {
            return null;
        }
        $kib = (int)$match[1];
        if ((string)$kib !== $match[1] || $kib <= 0 || $kib > intdiv(PHP_INT_MAX, self::BYTES_PER_KIB)) {
            return null;
        }
        $bytes = $kib * self::BYTES_PER_KIB;
        return ($bytes & ($bytes - 1)) === 0 ? $bytes : null;
    }
}
