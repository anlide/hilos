<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Daemon\Cli\CpuStats;

/** Reads node limits and aggregate CPU counters from Linux kernel pseudo-files. */
final class NodeLimitsReader
{
    private const int BYTES_PER_KIB = 1024;
    private const int CPU_STAT_SAMPLE_BYTES = 256;
    private const int CPU_FIELDS_WITH_LABEL = 9;

    /**
     * @param string $procRoot Procfs root, replaceable by tests
     * @param string $cgroupRoot Cgroup v2 root, replaceable by tests
     */
    public function __construct(
        private readonly string $procRoot = '/proc',
        private readonly string $cgroupRoot = '/sys/fs/cgroup',
    ) {
    }

    /** @return ?float Tightest cgroup v2 CPU quota in cores, or null when unknown or unlimited */
    public function cpuQuotaCores(): ?float
    {
        $paths = $this->cgroupPaths();
        if ($paths === null) {
            return null;
        }
        $minimum = null;
        foreach ($paths as $path) {
            $contents = self::read("{$path}/cpu.max");
            if ($contents === null) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($contents));
            if ($parts === false || count($parts) !== 2) {
                return null;
            }
            $period = self::positiveInt($parts[1]);
            if ($period === null) {
                return null;
            }
            if ($parts[0] === 'max') {
                continue;
            }
            $quota = self::positiveInt($parts[0]);
            if ($quota === null) {
                return null;
            }
            $cores = $quota / $period;
            $minimum = $minimum === null ? $cores : min($minimum, $cores);
        }
        return $minimum;
    }

    /** @return ?int Number of CPUs allowed to this process, or null when unavailable */
    public function allowedCpus(): ?int
    {
        $status = self::read("{$this->procRoot}/self/status");
        if ($status === null || preg_match('/^Cpus_allowed_list:\s*([^\r\n]+)$/m', $status, $match) !== 1) {
            return null;
        }
        $ranges = [];
        foreach (explode(',', trim($match[1])) as $piece) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($piece), $ends) !== 1) {
                return null;
            }
            $start = self::nonnegativeInt($ends[1]);
            $end = self::nonnegativeInt($ends[2] ?? $ends[1]);
            if ($start === null || $end === null || $end < $start) {
                return null;
            }
            $ranges[] = [$start, $end];
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $count = 0;
        $lastEnd = -1;
        foreach ($ranges as [$start, $end]) {
            $firstNew = max($start, $lastEnd + 1);
            if ($end >= $firstNew) {
                $added = $end - $firstNew + 1;
                if ($added > PHP_INT_MAX - $count) {
                    return null;
                }
                $count += $added;
            }
            $lastEnd = max($lastEnd, $end);
        }
        return $count > 0 ? $count : null;
    }

    /** @return ?int Tightest cgroup v2 memory ceiling in bytes, or null when unknown or unlimited */
    public function cgroupMemoryMax(): ?int
    {
        $paths = $this->cgroupPaths();
        if ($paths === null) {
            return null;
        }
        $minimum = null;
        foreach ($paths as $path) {
            $contents = self::read("{$path}/memory.max");
            if ($contents === null) {
                continue;
            }
            $value = trim($contents);
            if ($value === 'max') {
                continue;
            }
            $bytes = self::positiveInt($value);
            if ($bytes === null) {
                return null;
            }
            $minimum = $minimum === null ? $bytes : min($minimum, $bytes);
        }
        return $minimum;
    }

    /** @return ?int Physical memory in bytes, or null when unavailable */
    public function physicalMemory(): ?int
    {
        $meminfo = self::read("{$this->procRoot}/meminfo");
        if ($meminfo === null || preg_match('/^MemTotal:\s*(\d+)\s+kB$/m', $meminfo, $match) !== 1) {
            return null;
        }
        $kib = self::positiveInt($match[1]);
        return $kib === null || $kib > intdiv(PHP_INT_MAX, self::BYTES_PER_KIB)
            ? null : $kib * self::BYTES_PER_KIB;
    }

    /** @return ?CpuStats Aggregate machine CPU counters, or null when unavailable */
    public function machineCpu(): ?CpuStats
    {
        // warning-suppressed: /proc/stat may be unavailable; false means the machine load is unknown
        $contents = @file_get_contents("{$this->procRoot}/stat", false, null, 0, self::CPU_STAT_SAMPLE_BYTES);
        if ($contents === false) {
            return null;
        }
        $line = explode("\n", $contents, 2)[0];
        $fields = preg_split('/\s+/', trim($line));
        if ($fields === false || count($fields) < self::CPU_FIELDS_WITH_LABEL || $fields[0] !== 'cpu') {
            return null;
        }
        foreach (array_slice($fields, 1) as $field) {
            if (self::nonnegativeInt($field) === null) {
                return null;
            }
        }
        return CpuStats::fromProcStat(implode(' ', array_slice($fields, 0, self::CPU_FIELDS_WITH_LABEL)));
    }

    /** @return ?list<string> Paths from this cgroup to the hierarchy root */
    private function cgroupPaths(): ?array
    {
        $cgroup = self::read("{$this->procRoot}/self/cgroup");
        if ($cgroup === null || preg_match('/^0::(\/[^\r\n]*)$/m', $cgroup, $match) !== 1) {
            return null;
        }
        $relative = rtrim($match[1], '/');
        if (str_contains($relative, '/../') || str_ends_with($relative, '/..')) {
            return null;
        }
        $paths = [];
        do {
            $paths[] = rtrim($this->cgroupRoot, '/') . $relative;
            $relative = dirname($relative);
            if ($relative === '/' || $relative === '.') {
                $relative = '';
            }
        } while ($relative !== '' && count($paths) < 100);
        if ($paths[array_key_last($paths)] !== rtrim($this->cgroupRoot, '/')) {
            $paths[] = rtrim($this->cgroupRoot, '/');
        }
        return $paths;
    }

    /**
     * @param string $path Kernel pseudo-file path
     * @return ?string Pseudo-file contents, or null when unavailable
     */
    private static function read(string $path): ?string
    {
        // warning-suppressed: a kernel pseudo-file can disappear; false makes the individual limit unknown
        $contents = @file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    /**
     * @param string $value Decimal text to parse
     * @return ?int Positive decimal integer that fits PHP
     */
    private static function positiveInt(string $value): ?int
    {
        $number = self::nonnegativeInt($value);
        return $number !== null && $number > 0 ? $number : null;
    }

    /**
     * @param string $value Decimal text to parse
     * @return ?int Nonnegative decimal integer that fits PHP
     */
    private static function nonnegativeInt(string $value): ?int
    {
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $number = (int)$value;
        return $number >= 0 && (string)$number === $value ? $number : null;
    }
}
