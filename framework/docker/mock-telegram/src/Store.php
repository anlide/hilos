<?php

declare(strict_types=1);

namespace Hilos\MockTelegram;

/**
 * Store - the mock Gateway's state, which has to survive between requests.
 *
 * PHP's built-in server re-enters the script for every request and keeps nothing in
 * memory, so "what arrived" and "which numbers are declared absent from Telegram"
 * live in one JSON file under an exclusive lock. That is the whole of the storage
 * design, and it is enough: one runner, a few dozen writes per suite.
 *
 * The file is deliberately not a volume. State that outlives the container would
 * make a spec's outcome depend on what an earlier run left behind, which is exactly
 * the class of flake a stand exists to remove.
 */
final class Store
{
    /** Path of the state file inside the container. */
    private const string PATH = '/tmp/mock-telegram-state.json';

    /**
     * Records one delivered verification message.
     *
     * @param string $phoneNumber Recipient number as the Gateway was given it
     * @param array<string, mixed> $message Message fields a spec may read back
     */
    public static function addMessage(string $phoneNumber, array $message): void
    {
        self::mutate(static function (array $state) use ($phoneNumber, $message): array {
            $state['messages'][$phoneNumber][] = $message;

            return $state;
        });
    }

    /**
     * Reads every message delivered to one number, oldest first.
     *
     * @param string $phoneNumber Recipient number
     * @return list<array<string, mixed>> Messages, empty when nothing arrived
     */
    public static function messages(string $phoneNumber): array
    {
        return self::read()['messages'][$phoneNumber] ?? [];
    }

    /**
     * Declares whether a number can be reached on Telegram.
     *
     * @param string $phoneNumber Number to declare
     * @param bool $reachable Whether checkSendAbility should accept it
     */
    public static function setReachable(string $phoneNumber, bool $reachable): void
    {
        self::mutate(static function (array $state) use ($phoneNumber, $reachable): array {
            $state['reachable'][$phoneNumber] = $reachable;

            return $state;
        });
    }

    /**
     * Whether a number is reachable.
     *
     * Unknown numbers are reachable, which is the default a spec should not have to
     * arrange: the interesting case is the one a test declares absent on purpose.
     *
     * @param string $phoneNumber Number to check
     * @return bool True unless a spec declared this number absent
     */
    public static function isReachable(string $phoneNumber): bool
    {
        return self::read()['reachable'][$phoneNumber] ?? true;
    }

    /**
     * Forgets everything: no messages, no declared numbers.
     */
    public static function reset(): void
    {
        self::mutate(static fn(): array => ['messages' => [], 'reachable' => []]);
    }

    /**
     * @return array{messages: array<string, list<array<string, mixed>>>, reachable: array<string, bool>} Current state
     */
    private static function read(): array
    {
        $handle = @fopen(self::PATH, 'r');
        if ($handle === false) {
            return ['messages' => [], 'reachable' => []];
        }

        // Shared lock, because mutate() truncates before it writes: an unlocked read
        // landing in that window sees an empty file and answers "nothing arrived" for
        // a message that is there, which reads as a flaky test rather than as a race.
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded + ['messages' => [], 'reachable' => []] : ['messages' => [], 'reachable' => []];
    }

    /**
     * Applies one change to the state under an exclusive lock.
     *
     * @param callable(array): array $change Change to apply to the decoded state
     */
    private static function mutate(callable $change): void
    {
        $handle = fopen(self::PATH, 'c+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $decoded = json_decode((string)$raw, true);
        $state = is_array($decoded) ? $decoded + ['messages' => [], 'reachable' => []] : ['messages' => [], 'reachable' => []];

        $state = $change($state);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
