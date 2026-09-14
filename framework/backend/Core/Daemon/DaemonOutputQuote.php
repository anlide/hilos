<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Log\DaemonRawStream;

/**
 * Quotes what the daemon printed during this start.
 */
final class DaemonOutputQuote
{
    public const string PRINTED_NOTHING = '(the daemon printed nothing)';

    private const string NOT_READABLE = '(not readable)';
    private const string PRINTED_HERE = '(the daemon printed straight to this log)';

    /** @var array<string, int> Size each quoted stream had when the daemon started, keyed by path */
    private array $marks;

    /**
     * @param array<string, int> $marks Size each quoted stream had when the daemon started, keyed by path
     */
    private function __construct(array $marks)
    {
        $this->marks = $marks;
    }

    /**
     * @param ?string $logFile Configured daemon stdout log file
     * @param ?string $errorLogFile Configured daemon error log file
     * @return self Captured stream marks for this daemon run
     */
    public static function markedAt(?string $logFile, ?string $errorLogFile): self
    {
        $marks = [];
        if ($errorLogFile !== null) {
            $marks[$errorLogFile] = self::sizeOf($errorLogFile);
        }
        if ($logFile !== null) {
            $stdoutRaw = DaemonRawStream::pathFor($logFile);
            $marks[$stdoutRaw] = self::sizeOf($stdoutRaw);
        }
        if ($errorLogFile !== null) {
            $stderrRaw = DaemonRawStream::pathFor($errorLogFile);
            $marks[$stderrRaw] = self::sizeOf($stderrRaw);
        }

        return new self($marks);
    }

    /**
     * @param int $budgetBytes Total byte budget across all output chunks
     * @return string Operator-facing rendered quote
     */
    public function render(int $budgetBytes): string
    {
        /** @var list<array{text: string, names: list<string>}> $chunks */
        $chunks = [];

        foreach ($this->marks as $path => $mark) {
            $text = self::appendedText($path, $mark, $budgetBytes);
            if ($text === null) {
                continue;
            }

            $name = basename($path);
            $found = false;
            foreach ($chunks as &$chunk) {
                if ($chunk['text'] === $text) {
                    $chunk['names'][] = $name;
                    $found = true;
                    break;
                }
            }
            unset($chunk);

            if (!$found) {
                $chunks[] = [
                    'text' => $text,
                    'names' => [$name],
                ];
            }
        }

        if ($chunks === []) {
            return $this->marks === [] ? self::PRINTED_HERE : self::PRINTED_NOTHING;
        }

        $share = max(1, intdiv($budgetBytes, count($chunks)));
        $lines = [];
        foreach ($chunks as $chunk) {
            $text = $chunk['text'];
            if (strlen($text) > $share) {
                $text = substr($text, -$share);
            }
            $lines[] = implode(', ', $chunk['names']) . ': ' . $text;
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $path File path to read from
     * @param int $mark Offset where the daemon started
     * @param int $budgetBytes Byte limit to read
     * @return ?string Appended text, null if absent or empty, or NOT_READABLE marker
     */
    private static function appendedText(string $path, int $mark, int $budgetBytes): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        // warning-suppressed: the file may disappear or be unreadable, the caller reports not readable
        $size = @filesize($path);
        if ($size === false) {
            return self::NOT_READABLE;
        }

        $from = $size < $mark ? 0 : $mark;
        $from = max($from, $size - $budgetBytes);
        if ($size <= $from) {
            return null;
        }

        // warning-suppressed: the log can rotate away or fail to read, the caller reports not readable
        $text = @file_get_contents($path, false, null, $from);
        if ($text === false) {
            return self::NOT_READABLE;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param string $path Path to check size for
     * @return int Size in bytes, or 0 if absent or unreadable
     */
    private static function sizeOf(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        // warning-suppressed: the file may disappear between is_file and filesize, 0 is returned on failure
        $size = @filesize($path);

        return $size !== false ? $size : 0;
    }
}
