<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces process-fork.md: the PHP process is never forked by a call to pcntl_fork()
 * or pcntl_rfork().
 *
 * A fork creates a child process that inherits everything this process holds — the live
 * PHPUnit run, the daemon sockets, the database connection, and event loops. In a test
 * suite, an unhandled child runs the remaining tests concurrently or hangs the run; in
 * a daemon, an inherited socket or database connection corrupts the state of both
 * processes (the regression of HIL-732, removed with HIL-929).
 *
 * pcntl_exec() is deliberately not in the family: replacing the process image inherits
 * nothing except open file descriptors (see AiToolingInstallerTest.php:215).
 *
 * proc_open() and Hilos\Core\Process are not judged: they are the canonical way to start
 * an isolated process with its own address space (see Process.php:126,
 * BaseManager.php:35 PROCESS_FUNCTIONS).
 *
 * It judges every root rather than production alone. A test that forks the process is
 * wrong in the same way and hangs the suite instead of the node, so this id is absent
 * from the production-only list in {@see RootKind} on purpose.
 *
 * Blind spots declared in the owning document:
 * - Invocation through a variable ($f = 'pcntl_fork'; $f());
 * - Invocation through call_user_func('pcntl_fork');
 * - Invocation through an alias (use function pcntl_fork as spawn; spawn()).
 *
 * Only tokens in call position are read, so one of these names quoted in a string,
 * written in a comment, or worn by a method of some object is not a hit. A name carrying
 * a namespace is not a hit either, and T_NAME_QUALIFIED is deliberately absent from the
 * tokens below: App\pcntl_fork() names a function of that namespace, and PHP's fallback
 * to the global one is reserved for unqualified names.
 */
final class ProcessForkRule implements CodeStyleRule
{
    public const string ID = 'PROCESS-FORK';

    private const string DOC = 'docs/agents/code-style/process-fork.md';

    /**
     * The PHP builtins that fork the running process.
     *
     * @var array<int, string>
     */
    private const array FORKING_FUNCTIONS = [
        'pcntl_fork',
        'pcntl_rfork',
    ];

    /**
     * Tokens that mean the name belongs to something else — a method, a class constant,
     * a declaration — rather than naming the global function being called.
     *
     * @var array<int, int>
     */
    private const array NOT_A_GLOBAL_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];

    /**
     * Files allowed to fork the process anyway. Empty, and that is the current truth of
     * the tree rather than an oversight: there is not one such call in Hilos today.
     *
     * A line added here is the rule working as intended, not a way around it — but it
     * owes a leaf ticket number and a reason stating why this fork is worth its price.
     *
     * @var array<string, string>
     */
    public const array ALLOWED_FORKS = [];

    /**
     * @param string $root Scanned root relative to the repository root
     */
    public function __construct(
        private readonly string $root,
    ) {
    }

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
     * @return iterable<Violation> One entry per forking call
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (array_key_exists($this->root . '/' . $relativePath, self::ALLOWED_FORKS)) {
            return;
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $function = $this->calledFunction($tokens, $index);
            if ($function === null) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $token[2],
                $function . '() forks the PHP process, and the child inherits everything this one holds — '
                    . 'the live PHPUnit run, the daemon sockets, the database connection; start a process of its own '
                    . 'through Hilos\\Core\\Process, or name this file in the rule\'s list with the leaf where the owner allowed it',
            );
        }
    }

    /**
     * Reads the name at this index as a call to one of the forking builtins, or as
     * nothing at all.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @return ?string The forking function called here, or null when this is not such a call
     */
    private function calledFunction(array $tokens, int $index): ?string
    {
        $name = $tokens[$index];
        if (!is_array($name)) {
            return null;
        }

        $written = ltrim($name[1], '\\');
        if (str_contains($written, '\\')) {
            return null;
        }

        $shortName = strtolower($written);
        if (!in_array($shortName, self::FORKING_FUNCTIONS, true)) {
            return null;
        }

        if ($this->significantToken($tokens, $index, 1) !== '(') {
            return null;
        }

        $before = $this->significantToken($tokens, $index, -1);
        if (is_array($before) && in_array($before[0], self::NOT_A_GLOBAL_CALL, true)) {
            return null;
        }

        return $shortName;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token)) {
                return $token;
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Checks an allow list's format and returns any problems found.
     *
     * @param array<mixed, mixed> $entries
     * @return array<int, string>
     */
    public static function allowListProblems(array $entries): array
    {
        $problems = [];

        foreach ($entries as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '.php')) {
                $problems[] = sprintf('allow-list key "%s" is no PHP file path', (string) $key);
            }

            if (!is_string($value) || !preg_match('/^HIL-\d+ — (.*)$/su', $value, $matches)) {
                $problems[] = sprintf(
                    'allow-list entry "%s" names no leaf where the owner allowed the fork: %s',
                    (string) $key,
                    (string) $value,
                );
                continue;
            }

            if (trim($matches[1]) === '') {
                $problems[] = sprintf('allow-list entry "%s" gives no reason: %s', (string) $key, $value);
            }
        }

        return $problems;
    }
}
