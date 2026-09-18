<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\LogStreamConstants;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;

/**
 * The one place that names and writes the daemon's agent log streams (HIL-1017).
 *
 * An agent-format line reaches its stream from two places: the master's pipe reader filing a
 * worker's output ({@see WorkerServer}), and the master's own {@see Logger} filing lines
 * written by master-side code (protected-mode watchdog, alert notifier, agent manager).
 *
 * Composing the stream name and shaping the line (putting the level after the timestamp on the main
 * stream, bare timestamp on the error twin) from two separate places would allow them to drift
 * apart silently. Both call sites therefore file agent lines through this class.
 */
final class AgentLogStream
{
    private const string AGENT_ID_SANITIZE_PATTERN = '/[^a-zA-Z0-9_-]/';
    private const string AGENT_ID_SANITIZE_REPLACEMENT = '_';

    /**
     * Compose the path to the live agent log stream file.
     *
     * @param string $logDirectory Directory where live log streams are written
     * @param string $agentId Agent identifier, sanitized into the stream filename
     * @param bool $errorStream Whether to return the path to the error twin (.error.log)
     * @return string Path of the agent stream file
     */
    public static function pathFor(string $logDirectory, string $agentId, bool $errorStream): string
    {
        $safeAgentId = preg_replace(self::AGENT_ID_SANITIZE_PATTERN, self::AGENT_ID_SANITIZE_REPLACEMENT, $agentId);
        $extension = $errorStream ? LogStreamConstants::ERROR_STREAM_SUFFIX : LogStreamConstants::STREAM_SUFFIX;

        return $logDirectory . '/' . LogStreamConstants::AGENT_STREAM_PREFIX . $safeAgentId . $extension;
    }

    /**
     * Append an agent log line to the appropriate agent stream file.
     *
     * @param string $logDirectory Directory where live log streams are written
     * @param string $agentId Agent identifier, sanitized into the stream filename
     * @param string $level Log level (INFO, ERROR, WARNING, DEBUG)
     * @param string $message Message with timestamp prefix, e.g. [stamp] text
     * @param bool $fromErrorStream Whether the message arrived on the error stream / stderr
     */
    public static function append(
        string $logDirectory,
        string $agentId,
        string $level,
        string $message,
        bool $fromErrorStream,
    ): void {
        $toErrorStream = $level === Logger::LEVEL_ERROR || $fromErrorStream;
        $line = $toErrorStream ? $message : self::withLevelAfterStamp($level, $message);
        file_put_contents(self::pathFor($logDirectory, $agentId, $toErrorStream), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Put an agent line's level between its stamp and its text, the form {@see Logger} writes when it shows the level.
     *
     * The stamp is already in the message ({@see Logger::logAgent()}), so the level goes after it,
     * where the reader looks for one. A message without a stamp is written as it came.
     *
     * @param string $level Level field of the agent log line
     * @param string $message Message field of the agent log line, `[stamp] text`
     * @return string Line for the agent's main stream, without the trailing newline
     */
    private static function withLevelAfterStamp(string $level, string $message): string
    {
        if (preg_match(LogLineReader::TIMESTAMP_PREFIX_PATTERN, $message, $match) !== 1) {
            return $message;
        }

        return $match[0] . "[{$level}] " . substr($message, strlen($match[0]));
    }
}
