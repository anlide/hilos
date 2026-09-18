<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * Store - the stand gateway's state, which has to survive between requests.
 *
 * Which numbers are declared absent from Telegram, how a provider route answers the calls
 * a spec declared a behavior for, the world of the OAuth emulator - which accounts exist
 * at a provider, and which codes and tokens it has handed out - and what the local model
 * answers next live in one JSON file under an exclusive lock. That is the whole of the storage design, and it is enough:
 * one runner, a few writes per suite. A resident keeps no store of its own.
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
    private const array EMPTY_STATE = [
        'reachable' => [],
        'behaviors' => [],
        'oauthAccounts' => [],
        'oauthCodes' => [],
        'oauthTokens' => [],
        'answers' => [],
    ];

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
     * Puts a dictated model answer at the end of the queue of one key.
     *
     * A queue for the same reason a behavior has one: one dictation answers one call, and three
     * dictations on one key answer three calls in the order they were made - which is how a spec
     * writes "first a refusal, then a permission". The route is not part of the key, because the
     * model has one.
     *
     * @param string $key String the answer is keyed by, looked for in the prompt of a call
     * @param string $response Text the model answers with
     */
    public static function pushAnswer(string $key, string $response): void
    {
        self::mutate(static function (array $state) use ($key, $response): array {
            $state['answers'][$key][] = $response;

            return $state;
        });
    }

    /**
     * Takes the model answer dictated next for one key.
     *
     * A key nothing is dictated for is answered off the shared lock and does not rewrite the file.
     * A taken answer is gone, and an emptied queue is removed with it.
     *
     * @param string $key String the answer is keyed by
     * @return ?string Text to answer the call with, null when none is dictated
     */
    public static function takeAnswer(string $key): ?string
    {
        if (!isset(self::read()['answers'][$key])) {
            return null;
        }

        $taken = null;
        self::mutate(static function (array $state) use ($key, &$taken): array {
            if (!isset($state['answers'][$key])) {
                return $state;
            }

            $taken = array_shift($state['answers'][$key]);
            if ($state['answers'][$key] === []) {
                unset($state['answers'][$key]);
            }

            return $state;
        });

        return $taken;
    }

    /**
     * The first announced key a prompt contains.
     *
     * Looked for among the keys of dictated answers first and then among the keys of behaviors
     * declared for the route, each in the order its queue was opened; the first key the
     * prompt contains as a substring wins. The answers go first because a dictated answer is the
     * case this search exists for; the behaviors are searched at all so a spec that wants the route
     * to fail - a 500, a delay, a cut - does not also have to dictate a text it never expects.
     *
     * @param string $path Provider route whose behaviors are searched too
     * @param string $prompt Prompt of the call
     * @return ?string First announced key the prompt contains, null when it contains none
     */
    public static function keyAnnouncedIn(string $path, string $prompt): ?string
    {
        $state = self::read();
        $keys = [...array_keys($state['answers']), ...array_keys($state['behaviors'][$path] ?? [])];

        foreach ($keys as $key) {
            if (str_contains($prompt, (string)$key)) {
                return (string)$key;
            }
        }

        return null;
    }

    /**
     * Declares one account of a provider's world, replacing whatever was declared for that id.
     *
     * @param string $profile Value of the {@see OAuthProfile} case the account lives at
     * @param OAuthAccount $account Account as the spec declared it
     */
    public static function putOAuthAccount(string $profile, OAuthAccount $account): void
    {
        self::mutate(static function (array $state) use ($profile, $account): array {
            $state['oauthAccounts'][$profile][$account->subject] = $account->toArray();

            return $state;
        });
    }

    /**
     * One account of a provider's world.
     *
     * The worlds of two profiles are separate, as they are in life: the same id declared at
     * `github` and at `google` is two different accounts.
     *
     * @param string $profile Value of the {@see OAuthProfile} case to look in
     * @param string $subject Id of the account at that provider
     * @return ?OAuthAccount Declared account, null when no spec declared it
     */
    public static function oauthAccount(string $profile, string $subject): ?OAuthAccount
    {
        $stored = self::read()['oauthAccounts'][$profile][$subject] ?? null;

        return $stored === null ? null : OAuthAccount::fromArray($stored);
    }

    /**
     * Every account of a provider's world, in the order they were declared.
     *
     * @param string $profile Value of the {@see OAuthProfile} case to look in
     * @return list<OAuthAccount> Declared accounts, which the consent screen offers to choose from
     */
    public static function oauthAccounts(string $profile): array
    {
        return array_map(
            static fn(array $stored): OAuthAccount => OAuthAccount::fromArray($stored),
            array_values(self::read()['oauthAccounts'][$profile] ?? []),
        );
    }

    /**
     * Writes down the grant one authorization code stands for.
     *
     * @param string $code Code the consent screen handed to the browser
     * @param OAuthGrant $grant Grant the person gave
     */
    public static function issueOAuthCode(string $code, OAuthGrant $grant): void
    {
        self::mutate(static function (array $state) use ($code, $grant): array {
            $state['oauthCodes'][$code] = $grant->toArray();

            return $state;
        });
    }

    /**
     * Reads the grant behind a code WITHOUT spending it.
     *
     * For the one caller that must not spend anything: the key a behavior declaration is
     * matched by, which the routes work out before the handler runs and on every call.
     *
     * @param string $code Code to look up
     * @return ?OAuthGrant Grant behind the code, null when it is unknown or already spent
     */
    public static function peekOAuthCode(string $code): ?OAuthGrant
    {
        $stored = self::read()['oauthCodes'][$code] ?? null;

        return $stored === null ? null : OAuthGrant::fromArray($stored);
    }

    /**
     * Takes the grant behind a code, spending the code.
     *
     * A code is good once, which is the real thing and not an approximation of it: the second
     * exchange of the same code gets the provider's refusal, because the code is gone from here.
     *
     * @param string $code Code to spend
     * @return ?OAuthGrant Grant behind the code, null when it is unknown or already spent
     */
    public static function takeOAuthCode(string $code): ?OAuthGrant
    {
        if (!isset(self::read()['oauthCodes'][$code])) {
            return null;
        }

        $taken = null;
        self::mutate(static function (array $state) use ($code, &$taken): array {
            if (!isset($state['oauthCodes'][$code])) {
                return $state;
            }

            $taken = $state['oauthCodes'][$code];
            unset($state['oauthCodes'][$code]);

            return $state;
        });

        return $taken === null ? null : OAuthGrant::fromArray($taken);
    }

    /**
     * Writes down the grant one access token stands for.
     *
     * @param string $token Token the exchange handed out
     * @param OAuthGrant $grant Grant the spent code carried
     */
    public static function issueOAuthToken(string $token, OAuthGrant $grant): void
    {
        self::mutate(static function (array $state) use ($token, $grant): array {
            $state['oauthTokens'][$token] = $grant->toArray();

            return $state;
        });
    }

    /**
     * Reads the grant behind an access token; unlike a code, a token is not spent by being used.
     *
     * @param string $token Token to look up
     * @return ?OAuthGrant Grant behind the token, null when it is unknown
     */
    public static function oauthToken(string $token): ?OAuthGrant
    {
        $stored = self::read()['oauthTokens'][$token] ?? null;

        return $stored === null ? null : OAuthGrant::fromArray($stored);
    }

    /**
     * Forgets every declared number, every declared behavior, the whole world of the OAuth emulator, and every dictated model answer.
     */
    public static function reset(): void
    {
        self::mutate(static fn(): array => self::EMPTY_STATE);
    }

    /**
     * @return array{
     *     reachable: array<string, bool>,
     *     behaviors: array<string, array<string, list<array{status: ?int, delayMs: int, cut: bool, holdMs: int}>>>,
     *     oauthAccounts: array<string, array<string, array{subject: string, login: string, name: ?string, email: ?string}>>,
     *     oauthCodes: array<string, array{profile: string, subject: string, clientId: string, redirectUri: string, scope: string, issuedAt: int}>,
     *     oauthTokens: array<string, array{profile: string, subject: string, clientId: string, redirectUri: string, scope: string, issuedAt: int}>,
     *     answers: array<string, list<string>>
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
