<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Reads a process's resident bytes from kernel pseudo-files without starting a sampler. */
final class ProcessMetricsReader
{
    private const int BYTES_PER_KIB = 1024;
    private const int START_TICKS_FIELD_AFTER_COMM = 19;
    private const int RSS_FIELD_AFTER_COMM = 21;
    private const int PAGE_SIZE_SAMPLE_BYTES = 8192;

    private readonly ?int $pageSizeBytes;

    public function __construct()
    {
        $this->pageSizeBytes = self::detectPageSizeBytes();
    }

    /**
     * @param int $pid Process id to inspect
     * @return ?int Kernel start tick, or null when the process is unavailable
     */
    public function readStartTimeTicks(int $pid): ?int
    {
        $stat = self::readStat($pid);
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
        $stat = self::readStat($pid);
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
    private static function readStat(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }
        // warning-suppressed: /proc can disappear between roster and sample; null preserves unknown metrics
        $stat = @file_get_contents("/proc/{$pid}/stat");
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
    private static function detectPageSizeBytes(): ?int
    {
        // warning-suppressed: /proc may be absent off Linux; no page size means all RSS samples stay unknown
        $smaps = @file_get_contents('/proc/self/smaps', false, null, 0, self::PAGE_SIZE_SAMPLE_BYTES);
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
