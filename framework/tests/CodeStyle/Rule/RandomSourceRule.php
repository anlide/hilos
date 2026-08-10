<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces random-source.md: a secret is drawn from the secure axis of RandomHelper,
 * never from the tolerant one, which answers a refused entropy source with mt_rand().
 *
 * The rule inventories every caller rather than guessing which values are secrets:
 * `bytes()`, `hex()` and `integer()` are legal only from a file this list names, so
 * a new call site is a decision somebody makes on purpose. The next secret will not
 * appear where it is expected - a WebAuthn challenge, an OAuth state, a recovery
 * token - and a rule that watched a zone or a method name would let it through on
 * the very inattention that let today's hole in.
 *
 * The list lives here and not in `baseline.txt` because it is not debt: a baseline
 * record names the leaf that will pay it off and the file may only shrink, while
 * these callers are a standing choice that a new CLI command may legitimately join.
 *
 * Only real tokens are read, so `RandomHelper::hex` inside a comment or quoted in a
 * string literal is not a hit; the secure pair is not read at all, since it is legal
 * everywhere.
 */
final class RandomSourceRule implements CodeStyleRule
{
    public const string ID = 'RANDOM-SOURCE';

    private const string DOC = 'docs/agents/code-style/random-source.md';

    /** The helper both axes live on, named without its namespace the way a caller writes it. */
    private const string HELPER = 'RandomHelper';

    /**
     * The tolerant axis: every method that answers a refused source with pseudorandom.
     *
     * @var array<int, string>
     */
    private const array TOLERANT_METHODS = ['bytes', 'hex', 'integer'];

    /**
     * Files allowed to take the tolerant axis, each path relative to the backend root
     * it sits in - which is what the rule is handed, so one entry covers the two demos
     * that carry the same file. Every one of them wants values that only have to not
     * collide: a correlation id, a temporary directory name, an emitter identity, a
     * demo user's number, a bot's delay.
     *
     * Adding a line is the point of the rule, not a way around it: the reason belongs
     * in the file's own docblock, and a value anybody could have to guess belongs on
     * the secure pair instead.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_PATHS = [
        'Core/Agent/Hilos/AbstractHilosGuardianAgent.php',
        'Core/CLI/Commands/ClusterNodesCommand.php',
        'Core/CLI/Commands/ClusterReloadCommand.php',
        'Core/CLI/Commands/ClusterTestInspectCommand.php',
        'Core/CLI/Commands/CommandChannelClientTrait.php',
        'Core/CLI/Commands/ConnectionTestDropCommand.php',
        'Core/CLI/Commands/PingCommand.php',
        'Core/Router/SignalRouter.php',
        'Fs/FsTmpDirectory.php',
        'Agents/BotAgent.php',
        'CLI/Commands/AbstractImpersonateCommand.php',
        'CLI/Commands/AbstractSetAdminCommand.php',
        'CLI/Commands/AccountMergeCommand.php',
        'CLI/Commands/EchoCommand.php',
        'Database/Actions/Collection/UsersActions.php',
    ];

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per tolerant call from a file the list does not name
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (in_array($relativePath, self::ALLOWED_PATHS, true)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                continue;
            }

            $helper = $this->significantToken($tokens, $index, -1);
            $method = $this->significantToken($tokens, $index, 1);
            if ($helper === null || $method === null || $method[0] !== T_STRING) {
                continue;
            }

            if ($this->shortName($helper[1]) !== self::HELPER || !in_array($method[1], self::TOLERANT_METHODS, true)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $helper[2],
                self::HELPER . '::' . $method[1] . '() falls back to pseudorandom; a secret takes secureBytes()'
                    . '/secureHex(), and a value that only has to be unique takes this file into the rule\'s list',
            );
        }
    }

    /**
     * Walks away from `::` in one direction to the token that carries meaning, so a
     * call broken across lines or written with a comment inside it reads the same.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `::` token
     * @param int $step Direction to walk in: -1 for the class side, 1 for the method side
     * @return ?array{0: int, 1: string, 2: int} The token found, or null when it is a single-character one
     */
    private function significantToken(array $tokens, int $index, int $step): ?array
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token)) {
                return null;
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $token;
            }
        }

        return null;
    }

    /**
     * A class is written either by its imported short name or fully qualified, and
     * PHP hands back the whole name as one token; the tail is what names the class.
     *
     * @param string $name Class name as written at the call site
     * @return string The name without its namespace
     */
    private function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
