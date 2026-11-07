<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Moderation\DTO\ModerationFailure;
use Hilos\Utils\Logger;
use Hilos\Utils\RepeatedFailureWindows;

/**
 * Bounded diagnostic windows belonging to one moderator's agent log stream.
 *
 * No other process reads this counter; its unwritten tail may be lost on a crash.
 */
final class ModerationFailureLog
{
    public const int BURST_LINES = 3;
    public const float WINDOW_SECONDS = 60.0;
    public const int MAX_GROUPS = 128;
    public const int RAW_MAX_BYTES = 512;
    public const int REASON_MAX_BYTES = 512;

    private const string MESSAGE_PREFIX = 'Moderation check failure ';
    private const string FIELD_SAMPLE = 'sample';
    private const string FIELD_FIRST_REQUEST_ID = 'firstRequestId';
    private const string FIELD_CLOSED_KEY = 'key';
    private const string FIELD_CLOSED_HELD = 'held';

    private readonly RepeatedFailureWindows $windows;

    /** @var array<string, array{sample: ModerationFailure, firstRequestId: string}> Insertion-ordered window metadata */
    private array $groups = [];

    /** @param string $agentId Owner of the agent log stream */
    public function __construct(private readonly string $agentId)
    {
        $this->windows = new RepeatedFailureWindows(self::BURST_LINES, self::WINDOW_SECONDS);
    }

    /**
     * @param ModerationFailure $failure Already-redacted bounded snapshot
     * @param float $now Caller time in seconds, microtime(true) in production
     */
    public function write(ModerationFailure $failure, float $now): void
    {
        $this->flushExpired($now);
        $key = $failure->groupKey();
        if (!isset($this->groups[$key])) {
            if (count($this->groups) >= self::MAX_GROUPS) {
                $oldestKey = array_key_first($this->groups);
                $this->closeWindows($this->windows->closeKey($oldestKey));
            }
            $this->groups[$key] = [self::FIELD_SAMPLE => $failure, self::FIELD_FIRST_REQUEST_ID => $failure->requestId];
        } else {
            $this->groups[$key][self::FIELD_SAMPLE] = $failure;
        }
        if ($this->windows->admits($key, $now)) {
            $this->emit($failure);
        }
    }

    /** @param float $now Caller time in seconds, including an idle tick */
    public function flushExpired(float $now): void
    {
        $this->closeWindows($this->windows->closeExpired($now));
    }

    /** Closes every window once, including any replaced windows, before a normal stop. */
    public function flushAll(): void
    {
        $this->closeWindows($this->windows->closeAll());
    }

    /**
     * @param list<array{key: string, held: int}> $closed Infrastructure windows handed over by the shared limiter
     */
    private function closeWindows(array $closed): void
    {
        foreach ($closed as $window) {
            $key = $window[self::FIELD_CLOSED_KEY];
            if ($window[self::FIELD_CLOSED_HELD] > 0) {
                $this->emit(
                    $this->groups[$key][self::FIELD_SAMPLE],
                    $window[self::FIELD_CLOSED_HELD],
                    $this->groups[$key][self::FIELD_FIRST_REQUEST_ID],
                );
            }
            unset($this->groups[$key]);
        }
    }

    /**
     * @param ModerationFailure $failure Representative attempt of this entry
     * @param int $suppressed Additional events in a summary, or zero for a detailed entry
     * @param ?string $firstRequestId First request in a summary, otherwise null
     */
    private function emit(ModerationFailure $failure, int $suppressed = 0, ?string $firstRequestId = null): void
    {
        $message = self::MESSAGE_PREFIX . json_encode(
            $failure->toArray($suppressed > 0, $suppressed, $firstRequestId),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($failure->outcome === ModerationFailure::MALFORMED_ANSWER) {
            Logger::logAgentWarning($this->agentId, $message);
        } else {
            Logger::logAgentError($this->agentId, $message);
        }
    }
}
