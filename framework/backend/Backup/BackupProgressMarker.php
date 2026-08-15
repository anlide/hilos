<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupProgressMarker - the line protocol a backup child process reports its phase on.
 *
 * One line per phase entered, printed to stdout: `hilos-backup-phase <value>`, where the value is
 * a case value of {@see BackupPhase} or {@see RestorePhase}. Stdout is used because the pipe is
 * already read every tick by the supervising process, so the channel costs nothing to open.
 *
 * The protocol is deliberately one-way and lossy-tolerant: a line that does not carry the prefix
 * is the child's own output and is ignored, an unknown token is dropped, and a line that has not
 * arrived whole yet waits for its line break. A child older or newer than its supervisor therefore
 * still runs - it just reports fewer phases, or none.
 */
final class BackupProgressMarker
{
    /** Line prefix that tells a phase announcement from anything else the child prints. */
    public const string PREFIX = 'hilos-backup-phase';

    /** What separates the prefix from the phase value, and what ends the line. */
    private const string SEPARATOR = ' ';

    /**
     * The line a child prints when it enters a phase.
     *
     * @param string $phaseValue Case value of {@see BackupPhase} or {@see RestorePhase}
     * @return string Ready-to-print line, line break included
     */
    public static function statement(string $phaseValue): string
    {
        return self::PREFIX . self::SEPARATOR . $phaseValue . PHP_EOL;
    }

    /**
     * Reads every whole marker line out of an accumulated chunk of child stdout.
     *
     * @param string $buffer Everything read from the child since the last unconsumed tail
     * @return BackupProgressRead Recognized phase values and the fragment still waiting for its line break
     */
    public static function read(string $buffer): BackupProgressRead
    {
        $lines = explode("\n", $buffer);
        $tail = (string)array_pop($lines);

        $phases = [];
        foreach ($lines as $line) {
            $phase = self::phaseValueOf(rtrim($line, "\r"));
            if ($phase !== null) {
                $phases[] = $phase;
            }
        }

        return new BackupProgressRead($phases, $tail);
    }

    /**
     * The phase value a whole line announces, if it announces one at all.
     *
     * @param string $line One complete line of child output, without its line break
     * @return ?string Known phase value, or null when the line is not a marker or names no known phase
     */
    private static function phaseValueOf(string $line): ?string
    {
        $prefix = self::PREFIX . self::SEPARATOR;
        if (!str_starts_with($line, $prefix)) {
            return null;
        }

        $value = substr($line, strlen($prefix));
        $known = BackupPhase::tryFrom($value) !== null || RestorePhase::tryFrom($value) !== null;

        return $known ? $value : null;
    }
}
