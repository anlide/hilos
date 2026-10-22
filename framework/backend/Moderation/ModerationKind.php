<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Core\Exception\InvalidArgumentException;

/**
 * One kind of check a project sends to the moderator (HIL-1623).
 *
 * The key is the stable vocabulary that addresses the kind's rules and its checks. The title
 * names it on the administration screen, the input says what the moderator looks at, the
 * reasons are the codes a refusal may carry, the default rules apply while an administrator
 * has written none of their own, and the verdict goes to the agent named here, which writes
 * the subject itself.
 *
 * A developer declares it in a {@see ModerationKindDirectory}; it is not a reader of a person's
 * payload, so a bad value is a mistake in the declaration.
 */
final readonly class ModerationKind
{
    /** Key and reason-code grammar: ASCII lower_snake_case. */
    private const string CODE_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /** Longest kind key, in characters. */
    private const int KEY_MAX_CHARACTERS = 64;

    /** The code a kind may not take for a refusal reason. */
    private const string RESERVED_REASON = 'ok';

    /**
     * @param string $key Stable kind key
     * @param string $title Administration-screen title
     * @param ModerationInput $input What the moderator is handed to look at
     * @param list<string> $reasons Codes a refusal of this kind may carry
     * @param list<string> $defaultRules Rules that apply while an administrator has written none for the kind
     * @param string $verdictTo Agent type the verdict is sent to
     * @throws InvalidArgumentException When the key is malformed, the title or the recipient is empty, a reason is
     *     malformed, repeated or reserved, or a rule is not a string with content
     */
    public function __construct(
        public string $key,
        public string $title,
        public ModerationInput $input,
        public array $reasons,
        public array $defaultRules,
        public string $verdictTo,
    ) {
        if (strlen($key) > self::KEY_MAX_CHARACTERS || preg_match(self::CODE_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid moderation kind key: {$key}");
        }
        if (trim($title) === '') {
            throw new InvalidArgumentException("Moderation kind {$key} has an empty title");
        }
        if (trim($verdictTo) === '') {
            throw new InvalidArgumentException("Moderation kind {$key} names no verdict recipient");
        }
        self::assertReasons($key, $reasons);
        self::assertRules($key, $defaultRules);
    }

    /**
     * @param string $key Kind key, for the message
     * @param array<mixed> $reasons Declared refusal reasons
     * @throws InvalidArgumentException When the reasons are not a list of distinct, well-formed, unreserved codes
     */
    private static function assertReasons(string $key, array $reasons): void
    {
        if (!array_is_list($reasons)) {
            throw new InvalidArgumentException("Moderation kind {$key} reasons must be a list");
        }
        $seen = [];
        foreach ($reasons as $reason) {
            if (!is_string($reason) || preg_match(self::CODE_PATTERN, $reason) !== 1) {
                throw new InvalidArgumentException(
                    "Moderation kind {$key} declares an invalid reason: " . (is_string($reason) ? $reason : get_debug_type($reason)),
                );
            }
            if ($reason === self::RESERVED_REASON) {
                throw new InvalidArgumentException("Moderation kind {$key} declares the reserved reason {$reason}");
            }
            if (isset($seen[$reason])) {
                throw new InvalidArgumentException("Moderation kind {$key} declares the reason {$reason} twice");
            }
            $seen[$reason] = true;
        }
    }

    /**
     * @param string $key Kind key, for the message
     * @param array<mixed> $rules Declared default rules
     * @throws InvalidArgumentException When the rules are not a list of strings with content
     */
    private static function assertRules(string $key, array $rules): void
    {
        if (!array_is_list($rules)) {
            throw new InvalidArgumentException("Moderation kind {$key} default rules must be a list");
        }
        foreach ($rules as $position => $rule) {
            if (!is_string($rule) || trim($rule) === '') {
                throw new InvalidArgumentException("Moderation kind {$key} default rule {$position} is not a string with content");
            }
        }
    }
}
