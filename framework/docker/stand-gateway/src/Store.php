<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * Store - the stand gateway's state, which has to survive between requests.
 *
 * Which numbers are declared absent from Telegram, and how a provider route answers the
 * calls a spec declared a behavior for, live in one JSON file under an exclusive lock.
 * That is the whole of the storage design, and it is enough: one runner, a few writes per
 * suite.
 *
 * The file was forced by PHP's built-in server, which re-entered the script for every
 * request and kept nothing in memory. The gateway has been one long-lived process since
 * it moved onto the framework's server (HIL-921), so that reason is gone; the file stays
 * because it still works, not because memory would not.
 *
 * What arrived is deliberately NOT here (HIL-653). A caught message leaves as a letter
 * to Mailpit, so the readable side of every channel is the inbox a person already
 * opens, and this file holds only what a spec arranges up front.
 *
 * The file is deliberately not a volume. State that outlives the container would
 * make a spec's outcome depend on what an earlier run left behind, which is exactly
 * the class of flake a stand exists to remove.
 */
final class Store
{
    /** Path of the state file inside the container. */
    private const string PATH = '/tmp/stand-gateway-state.json';

    /** State before any spec arranged anything, and after a reset. */
    private const array EMPTY_STATE = ['reachable' => [], 'behaviors' => []];

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
     * Puts a declared behavior at the end of the queue of one provider route and key.
     *
     * A queue rather than one slot, because one declaration answers one call: a spec that
     * declares three refusals gets a provider that fails three retries, and a sequence of
     * different behaviors plays out in the order it was declared.
     *
     * @param string $path Provider route the behavior applies to
     * @param string $key Value of the call the behavior is keyed by
     * @param Behavior $behavior Declared behavior
     */
    public static function pushBehavior(string $path, string $key, Behavior $behavior): void
    {
        self::mutate(static function (array $state) use ($path, $key, $behavior): array {
            $state['behaviors'][$path][$key][] = $behavior->toArray();

            return $state;
        });
    }

    /**
     * Takes the behavior declared next for one call of a provider route.
     *
     * A call nothing was declared for is answered off the shared lock and does not rewrite
     * the file - that is nearly every call the product makes. A taken behavior is gone: an
     * emptied queue and an emptied route are removed with it.
     *
     * @param string $path Provider route that was called
     * @param string $key Value of the call the behavior is keyed by
     * @return ?Behavior Behavior to play out on this call, null when none is declared
     */
    public static function takeBehavior(string $path, string $key): ?Behavior
    {
        if (!isset(self::read()['behaviors'][$path][$key])) {
            return null;
        }

        $taken = null;
        self::mutate(static function (array $state) use ($path, $key, &$taken): array {
            if (!isset($state['behaviors'][$path][$key])) {
                return $state;
            }

            $taken = array_shift($state['behaviors'][$path][$key]);
            if ($state['behaviors'][$path][$key] === []) {
                unset($state['behaviors'][$path][$key]);
            }
            if ($state['behaviors'][$path] === []) {
                unset($state['behaviors'][$path]);
            }

            return $state;
        });

        return $taken === null ? null : Behavior::fromArray($taken);
    }

    /**
     * Forgets every declared number and every declared behavior.
     */
    public static function reset(): void
    {
        self::mutate(static fn(): array => self::EMPTY_STATE);
    }

    /**
     * @return array{
     *     reachable: array<string, bool>,
     *     behaviors: array<string, array<string, list<array{status: ?int, delayMs: int, cut: bool, holdMs: int}>>>
     * } Current state
     */
    private static function read(): array
    {
        $handle = @fopen(self::PATH, 'r');
        if ($handle === false) {
            return self::EMPTY_STATE;
        }

        // Shared lock, because mutate() truncates before it writes: an unlocked read
        // landing in that window sees an empty file and answers "reachable" for a
        // number a spec declared absent, which reads as a flaky test rather than as
        // a race.
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded + self::EMPTY_STATE : self::EMPTY_STATE;
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
        $state = is_array($decoded) ? $decoded + self::EMPTY_STATE : self::EMPTY_STATE;

        $state = $change($state);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
