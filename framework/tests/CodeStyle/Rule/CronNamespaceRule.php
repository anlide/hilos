<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupScheduleEntry;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces daemon-lifecycle.md: a cron declaration names who owns the name.
 *
 * Under framework/backend every `new CronRule` passes CronRuleOrigin::FRAMEWORK explicitly.
 * Three bridges are the exception, each for the reason named here. DaemonManager::addCronRule
 * builds a project rule, so the origin is omitted or CronRuleOrigin::PROJECT.
 * DaemonManager::registerBackupCronRules and BackupAgent::buildAgentCronRules pass
 * `$entry->origin`, which the schedule entry already carries. Anywhere else under that root
 * an omitted, project or computed origin is a hit.
 *
 * Under a demo backend and under scripts the same constructions are project declarations.
 * `new CronRule` and `new BackupScheduleEntry` omit the origin or pass CronRuleOrigin::PROJECT.
 * FRAMEWORK and a computed origin are hits. The fifth argument of BackupScheduleEntry is that
 * origin, positional or named. The framework backend is not judged for BackupScheduleEntry:
 * its default entry is the framework one, and fromArray keeps a raw row at the project default.
 *
 * A name is resolved the way PHP resolves it in this file: a fully qualified spelling as
 * written, a qualified one through the import its first segment names, a bare one through
 * its import, else against the namespace. An alias is followed. `self`, `static` and `parent`
 * are not names of a class. A grouped `use` (`use A\{B, C}`) is not followed; the tree does
 * not write one, and a name imported that way resolves against the namespace instead, which
 * is silent rather than a false hit. Strings, comments and a different class of the same
 * short name are not constructions. The bridge is the class and the method as declared, by
 * short name: a closure inside the method stays in it, and an anonymous class does not. No
 * call is followed into another function, and the caller's class is not loaded.
 *
 * Judged by root. framework/backend, demo/<name>/backend and scripts are the three shapes;
 * every other root is handed an empty report. The suite is withheld by RootKind, because a
 * test builds both origins on purpose.
 */
final class CronNamespaceRule implements CodeStyleRule
{
    public const string ID = 'CRON-NAMESPACE';

    private const string DOC = 'docs/agents/architecture/daemon-lifecycle.md';

    /** The one root whose constructions must be framework, bridges aside. */
    private const string FRAMEWORK_ROOT = 'framework/backend';

    /** Scripts are production code with the project policy, same as a demo backend. */
    private const string SCRIPTS_ROOT = 'scripts';

    /** A demo backend, and not its tests or its data directory. */
    private const string DEMO_BACKEND_ROOT = '#^demo/[^/]+/backend$#';

    private const string POLICY_FRAMEWORK = 'framework';

    private const string POLICY_PROJECT = 'project';

    /** addCronRule: omitted origin or an explicit project case. */
    private const string BRIDGE_PROJECT = 'project';

    /** The two backup bridges: the origin argument is `$entry->origin`. */
    private const string BRIDGE_ENTRY = 'entry';

    /** Declared name of the project registration bridge. */
    private const string ADD_CRON_RULE = 'addCronRule';

    /** Declared name of the daemon's backup bridge. */
    private const string REGISTER_BACKUP_CRON_RULES = 'registerBackupCronRules';

    /** Declared name of the backup agent's bridge. */
    private const string BUILD_AGENT_CRON_RULES = 'buildAgentCronRules';

    /** Parameter both constructors use for the origin. */
    private const string ORIGIN_PARAMETER = 'origin';

    /** Zero-based index of CronRule's origin. The third argument. */
    private const int CRON_RULE_ORIGIN_INDEX = 2;

    /** Zero-based index of BackupScheduleEntry's origin. The fifth argument. */
    private const int SCHEDULE_ENTRY_ORIGIN_INDEX = 4;

    /** Variable the backup bridges read the carried origin from, as written. */
    private const string ENTRY_VARIABLE = '$entry';

    /** Property those bridges read. The schedule entry's origin. */
    private const string ENTRY_PROPERTY = 'origin';

    private const string ORIGIN_FRAMEWORK = 'framework';

    private const string ORIGIN_PROJECT = 'project';

    private const string ORIGIN_ENTRY = 'entry';

    private const string ORIGIN_DYNAMIC = 'dynamic';

    private const string ORIGIN_OMITTED = 'omitted';

    /** Names that answer to the enclosing class rather than to a class of their own. */
    private const array SELF_REFERENCES = ['self', 'static', 'parent'];

    /**
     * Token types a name reaches the rule as, fully qualified spellings included.
     *
     * @var array<int, int>
     */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * Tokens that open a declaration; every `use` before the first of them is an import.
     *
     * @var array<int, int>
     */
    private const array DECLARATION_OPENERS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_FN];

    /**
     * Tokens a brace walk skips. A name inside one of them is not a declaration.
     *
     * @var array<int, int>
     */
    private const array IGNORABLE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /** Short name of the class that owns the project bridge and one backup bridge. */
    private readonly string $daemonManager;

    /** Short name of the class that owns the agent backup bridge. */
    private readonly string $backupAgent;

    /**
     * @param string $root Scanned root relative to the repository root
     */
    public function __construct(
        private readonly string $root,
    ) {
        $this->daemonManager = self::shortName(DaemonManager::class);
        $this->backupAgent = self::shortName(BackupAgent::class);
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
     * @return iterable<Violation> One entry per cron declaration the root's policy refuses
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $policy = $this->policy();
        if ($policy === null) {
            return;
        }

        $namespace = $this->declaredNamespace($tokens);
        $imports = $this->imports($tokens);
        $frames = [['class' => null, 'method' => null]];
        $pending = null;

        foreach ($tokens as $index => $token) {
            $pending = $this->trackScope($tokens, $index, $frames, $pending);
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            yield from $this->construction(
                $relativePath,
                $tokens,
                $index,
                $namespace,
                $imports,
                $policy,
                $frames[array_key_last($frames)],
            );
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `new` keyword
     * @param string $namespace Namespace this file declares, empty in the global one
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @param string $policy Which declarations this root allows
     * @param array{class: ?string, method: ?string} $scope Class and method enclosing the keyword
     * @return iterable<Violation> One entry when this construction breaks the root's policy
     */
    private function construction(
        string $relativePath,
        array $tokens,
        int $index,
        string $namespace,
        array $imports,
        string $policy,
        array $scope,
    ): iterable {
        $name = $this->significantToken($tokens, $index, 1);
        if (!is_array($name) || !$this->isClassName($name)) {
            return;
        }

        $constructed = $this->resolve($name, $namespace, $imports);
        if (!$this->isJudged($constructed, $policy)) {
            return;
        }

        $paren = $this->significantIndex($tokens, $index, 1);
        $paren = $paren === null ? null : $this->significantIndex($tokens, $paren, 1);
        if ($paren === null || $tokens[$paren] !== '(') {
            return;
        }

        $origin = $this->originKind(
            $tokens,
            $this->arguments($tokens, $paren),
            strcasecmp($constructed, BackupScheduleEntry::class) === 0
                ? self::SCHEDULE_ENTRY_ORIGIN_INDEX
                : self::CRON_RULE_ORIGIN_INDEX,
            $namespace,
            $imports,
        );
        $message = $policy === self::POLICY_FRAMEWORK
            ? $this->frameworkMessage($this->bridge($scope['class'], $scope['method']), $origin)
            : $this->projectMessage($constructed, $origin);
        if ($message === null) {
            return;
        }

        yield new Violation(self::ID, $relativePath, $tokens[$index][2], $message);
    }

    /**
     * @param string|null $className Short name of the enclosing class, absent at file scope
     * @param string|null $methodName Name of the enclosing method, absent outside one
     * @return string|null Bridge kind when this class and method are one of the three
     */
    private function bridge(?string $className, ?string $methodName): ?string
    {
        if ($className === null || $methodName === null) {
            return null;
        }

        if ($className === $this->daemonManager && $methodName === self::ADD_CRON_RULE) {
            return self::BRIDGE_PROJECT;
        }

        if ($className === $this->daemonManager && $methodName === self::REGISTER_BACKUP_CRON_RULES) {
            return self::BRIDGE_ENTRY;
        }

        if ($className === $this->backupAgent && $methodName === self::BUILD_AGENT_CRON_RULES) {
            return self::BRIDGE_ENTRY;
        }

        return null;
    }

    /**
     * @param string|null $bridge Bridge kind, absent when the construction is not in one
     * @param string $origin What the origin argument was resolved to
     * @return string|null Message when the framework policy refuses the origin
     */
    private function frameworkMessage(?string $bridge, string $origin): ?string
    {
        if ($bridge === self::BRIDGE_PROJECT) {
            if ($origin === self::ORIGIN_OMITTED || $origin === self::ORIGIN_PROJECT) {
                return null;
            }

            return 'DaemonManager::addCronRule registers a project rule, so its origin is omitted'
                . ' or CronRuleOrigin::PROJECT';
        }

        if ($bridge === self::BRIDGE_ENTRY) {
            if ($origin === self::ORIGIN_ENTRY) {
                return null;
            }

            return 'this bridge passes the schedule entry\'s origin through as $entry->origin,'
                . ' already resolved when the entry was built';
        }

        if ($origin === self::ORIGIN_FRAMEWORK) {
            return null;
        }

        return 'a framework cron rule passes CronRuleOrigin::FRAMEWORK explicitly; an omitted,'
            . ' project or computed origin does not count';
    }

    /**
     * @param string $constructed Class being built, resolved
     * @param string $origin What the origin argument was resolved to
     * @return string|null Message when the project policy refuses the origin
     */
    private function projectMessage(string $constructed, string $origin): ?string
    {
        if ($origin === self::ORIGIN_OMITTED || $origin === self::ORIGIN_PROJECT) {
            return null;
        }

        $written = strcasecmp($constructed, BackupScheduleEntry::class) === 0
            ? 'BackupScheduleEntry'
            : 'CronRule';

        return 'new ' . $written . ' in a project file omits its origin or passes CronRuleOrigin::PROJECT;'
            . ' framework or computed origin is refused';
    }

    /**
     * @param string $class Class being built, resolved
     * @param string $policy Which declarations this root allows
     * @return bool True when this construction is one the policy reads
     */
    private function isJudged(string $class, string $policy): bool
    {
        if (strcasecmp($class, CronRule::class) === 0) {
            return true;
        }

        return $policy === self::POLICY_PROJECT && strcasecmp($class, BackupScheduleEntry::class) === 0;
    }

    /**
     * @return string|null Framework policy, project policy, or null when this root is not judged
     */
    private function policy(): ?string
    {
        if ($this->root === self::FRAMEWORK_ROOT) {
            return self::POLICY_FRAMEWORK;
        }

        if ($this->root === self::SCRIPTS_ROOT || preg_match(self::DEMO_BACKEND_ROOT, $this->root) === 1) {
            return self::POLICY_PROJECT;
        }

        return null;
    }

    /**
     * Keeps the class and method that enclose the token.
     *
     * A `{` after a class or a named function opens that scope. A closure's brace does not:
     * the new inside it is still the enclosing method's declaration. An anonymous class does
     * open a scope, with no class name, so a bridge does not leak into it. String interpolation
     * opens a brace the tokenizer does not spell `{`, and that brace is counted so the `}`
     * that closes it does not close the method. A `;` before the brace is an abstract method
     * and drops the pending declaration.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Token being visited
     * @param array<int, array{class: ?string, method: ?string}> $frames Scope stack, outermost first
     * @param array{kind: string, name: ?string}|null $pending Declaration waiting for its opening brace
     * @return array{kind: string, name: ?string}|null Pending declaration after this token
     */
    private function trackScope(array $tokens, int $index, array &$frames, ?array $pending): ?array
    {
        $token = $tokens[$index];
        $id = is_array($token) ? $token[0] : $token;

        if ($id === ';') {
            return null;
        }

        if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
            return $this->pendingClass($tokens, $index, $pending);
        }

        if ($id === T_FUNCTION) {
            return $this->pendingFunction($tokens, $index, $pending);
        }

        if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $frame = $frames[array_key_last($frames)];
            if ($id === '{' && $pending !== null) {
                if ($pending['kind'] === 'class') {
                    $frame = ['class' => $pending['name'], 'method' => null];
                } elseif ($pending['kind'] === 'method') {
                    $frame['method'] = $pending['name'];
                }
                $pending = null;
            }
            $frames[] = $frame;

            return $pending;
        }

        if ($id === '}' && count($frames) > 1) {
            array_pop($frames);
        }

        return $pending;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the class-like keyword
     * @param array{kind: string, name: ?string}|null $pending Declaration already waiting
     * @return array{kind: string, name: ?string}|null Class declaration waiting for its brace, or the pending one when
     *     this keyword is `::class`
     */
    private function pendingClass(array $tokens, int $index, ?array $pending): ?array
    {
        $previous = $this->significantToken($tokens, $index, -1);
        if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
            return $pending;
        }

        if (is_array($previous) && $previous[0] === T_NEW) {
            return ['kind' => 'class', 'name' => null];
        }

        $name = $this->significantToken($tokens, $index, 1);
        if (!is_array($name) || $name[0] !== T_STRING) {
            return $pending;
        }

        return ['kind' => 'class', 'name' => $name[1]];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `function` keyword
     * @param array{kind: string, name: ?string}|null $pending Declaration already waiting
     * @return array{kind: string, name: ?string}|null Named method, or a closure that will not replace the method
     */
    private function pendingFunction(array $tokens, int $index, ?array $pending): ?array
    {
        $previous = $this->significantToken($tokens, $index, -1);
        if (is_array($previous) && $previous[0] === T_USE) {
            return $pending;
        }

        $next = $this->significantIndex($tokens, $index, 1);
        if ($next !== null && $tokens[$next] === '&') {
            $next = $this->significantIndex($tokens, $next, 1);
        }

        if ($next === null) {
            return $pending;
        }

        $token = $tokens[$next];
        if (is_array($token) && $token[0] === T_STRING) {
            return ['kind' => 'method', 'name' => $token[1]];
        }

        if ($token === '(') {
            return ['kind' => 'closure', 'name' => null];
        }

        return $pending;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, array{name: ?string, from: int, to: int}> $arguments Arguments of the construction
     * @param int $position Zero-based index of the origin among positional arguments
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return string Omitted, an explicit case, the entry property, or a value the rule cannot read
     */
    private function originKind(
        array $tokens,
        array $arguments,
        int $position,
        string $namespace,
        array $imports,
    ): string {
        foreach ($arguments as $argument) {
            if ($argument['name'] !== null && strcasecmp($argument['name'], self::ORIGIN_PARAMETER) === 0) {
                return $this->classify($tokens, $argument['from'], $argument['to'], $namespace, $imports);
            }
        }

        $positional = 0;
        foreach ($arguments as $argument) {
            if ($argument['name'] !== null) {
                continue;
            }

            if ($this->startsWithEllipsis($tokens, $argument['from'], $argument['to'])) {
                return self::ORIGIN_DYNAMIC;
            }

            if ($positional === $position) {
                return $this->classify($tokens, $argument['from'], $argument['to'], $namespace, $imports);
            }

            $positional++;
        }

        return self::ORIGIN_OMITTED;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from First token of the argument value
     * @param int $to Last token of the argument value
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return string Explicit framework or project case, the entry property, or a computed value
     */
    private function classify(array $tokens, int $from, int $to, string $namespace, array $imports): string
    {
        $indexes = $this->unwrap($tokens, $this->significantIndexes($tokens, $from, $to));
        if ($this->isEntryOrigin($tokens, $indexes)) {
            return self::ORIGIN_ENTRY;
        }

        $case = $this->explicitCase($tokens, $indexes, $namespace, $imports);
        if ($case !== null) {
            return $case;
        }

        return self::ORIGIN_DYNAMIC;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $indexes Significant tokens of the value, parentheses unwrapped
     * @return bool True when the value is exactly `$entry->origin`
     */
    private function isEntryOrigin(array $tokens, array $indexes): bool
    {
        if (count($indexes) !== 3) {
            return false;
        }

        $variable = $tokens[$indexes[0]];
        $operator = $tokens[$indexes[1]];
        $property = $tokens[$indexes[2]];

        return is_array($variable) && $variable[0] === T_VARIABLE && $variable[1] === self::ENTRY_VARIABLE
            && is_array($operator) && $operator[0] === T_OBJECT_OPERATOR
            && is_array($property) && $property[0] === T_STRING && $property[1] === self::ENTRY_PROPERTY;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $indexes Significant tokens of the value, parentheses unwrapped
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return string|null Framework or project when the value is exactly that case of CronRuleOrigin
     */
    private function explicitCase(array $tokens, array $indexes, string $namespace, array $imports): ?string
    {
        if (count($indexes) !== 3) {
            return null;
        }

        $name = $tokens[$indexes[0]];
        $operator = $tokens[$indexes[1]];
        $case = $tokens[$indexes[2]];
        if (!is_array($name) || !$this->isClassName($name) || !is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
            return null;
        }

        if (!is_array($case) || $case[0] !== T_STRING) {
            return null;
        }

        if (strcasecmp($this->resolve($name, $namespace, $imports), CronRuleOrigin::class) !== 0) {
            return null;
        }

        if ($case[1] === CronRuleOrigin::FRAMEWORK->name) {
            return self::ORIGIN_FRAMEWORK;
        }

        if ($case[1] === CronRuleOrigin::PROJECT->name) {
            return self::ORIGIN_PROJECT;
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from First token of the argument value
     * @param int $to Last token of the argument value
     * @return bool True when the argument itself is an unpack, rather than a call that contains one
     */
    private function startsWithEllipsis(array $tokens, int $from, int $to): bool
    {
        $indexes = $this->significantIndexes($tokens, $from, $to);
        if ($indexes === []) {
            return false;
        }

        $first = $tokens[$indexes[0]];

        return is_array($first) && $first[0] === T_ELLIPSIS;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $openIndex Index of the `(` that opens the argument list
     * @return array<int, array{name: ?string, from: int, to: int}> Arguments, named ones carrying the parameter
     */
    private function arguments(array $tokens, int $openIndex): array
    {
        $arguments = [];
        $depth = 1;
        $from = null;
        $to = null;
        $name = null;
        $expectingName = true;
        $count = count($tokens);

        for ($index = $openIndex + 1; $index < $count; $index++) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : $token;
            if (is_array($token) && in_array($id, self::IGNORABLE, true)) {
                continue;
            }

            if ($depth === 1 && $id === ')') {
                $this->pushArgument($arguments, $name, $from, $to);
                break;
            }

            if ($depth === 1 && $id === ',') {
                $this->pushArgument($arguments, $name, $from, $to);
                $from = null;
                $to = null;
                $name = null;
                $expectingName = true;
                continue;
            }

            if ($depth === 1 && $expectingName && is_array($token) && $id === T_STRING
                && $this->nextId($tokens, $index) === ':'
            ) {
                $name = $token[1];
                $colon = $this->significantIndex($tokens, $index, 1);
                $index = $colon ?? $index;
                $expectingName = false;
                continue;
            }

            if ($from === null) {
                $from = $index;
                $expectingName = false;
            }
            $to = $index;

            if ($id === '(' || $id === '[' || $id === '{' || $id === T_CURLY_OPEN
                || $id === T_DOLLAR_OPEN_CURLY_BRACES || $id === T_ATTRIBUTE
            ) {
                $depth++;
            } elseif ($id === ')' || $id === ']' || $id === '}') {
                $depth--;
            }
        }

        return $arguments;
    }

    /**
     * @param array<int, array{name: ?string, from: int, to: int}> $arguments Arguments collected so far
     * @param string|null $name Parameter name when the argument is named
     * @param int|null $from First token of the value, absent when the slot was empty
     * @param int|null $to Last token of the value
     */
    private function pushArgument(array &$arguments, ?string $name, ?int $from, ?int $to): void
    {
        if ($from === null || $to === null) {
            return;
        }

        $arguments[] = ['name' => $name, 'from' => $from, 'to' => $to];
    }

    /**
     * @param array{0: int, 1: string, 2: int} $token Name token
     * @return bool True when the token names a class of its own rather than the enclosing one
     */
    private function isClassName(array $token): bool
    {
        return in_array($token[0], self::NAME_TOKENS, true)
            && !in_array(strtolower($token[1]), self::SELF_REFERENCES, true);
    }

    /**
     * Resolves a name the way PHP does: a fully qualified spelling as written, a
     * qualified one through the import its first segment names, else against the
     * namespace, and a bare one through its import, else against the namespace.
     *
     * @param array{0: int, 1: string, 2: int} $token Name token
     * @param string $namespace Namespace this file declares, empty in the global one
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return string Fully qualified class name, without a leading separator
     */
    private function resolve(array $token, string $namespace, array $imports): string
    {
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token[1], '\\');
        }

        if ($token[0] === T_NAME_QUALIFIED) {
            [$head, $rest] = explode('\\', $token[1], 2);
            $imported = $imports[strtolower($head)] ?? null;

            return $imported === null ? $namespace . '\\' . $token[1] : $imported . '\\' . $rest;
        }

        $imported = $imports[strtolower($token[1])] ?? null;
        if ($imported !== null) {
            return $imported;
        }

        return $namespace === '' ? $token[1] : $namespace . '\\' . $token[1];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return string Namespace the file declares, empty when it declares none
     */
    private function declaredNamespace(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $name = $this->significantToken($tokens, $index, 1);
            if (is_array($name) && in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                return $name[1];
            }

            return '';
        }

        return '';
    }

    /**
     * The file's imports, read up to the first declaration: a `use` past that point is
     * a trait or a closure capture. A function or constant import names no class and adds
     * nothing; the walk steps over the whole statement either way.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<string, string> Imported names, lower-cased alias => fully qualified class
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        for ($index = 0; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], self::DECLARATION_OPENERS, true)) {
                break;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            $cursor = $this->significantIndex($tokens, $index, 1);
            while ($cursor !== null && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], self::NAME_TOKENS, true)) {
                $class = ltrim($tokens[$cursor][1], '\\');
                $alias = self::shortName($class);

                $next = $this->significantIndex($tokens, $cursor, 1);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_AS) {
                    $aliasIndex = $this->significantIndex($tokens, $next, 1);
                    if ($aliasIndex === null || !is_array($tokens[$aliasIndex])) {
                        break;
                    }

                    $alias = $tokens[$aliasIndex][1];
                    $next = $this->significantIndex($tokens, $aliasIndex, 1);
                }

                $imports[strtolower($alias)] = $class;
                if ($next === null || $tokens[$next] !== ',') {
                    break;
                }

                $cursor = $this->significantIndex($tokens, $next, 1);
            }

            $index = $this->statementEnd($tokens, $index);
        }

        return $imports;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from First token of the span
     * @param int $to Last token of the span
     * @return array<int, int> Indexes of tokens that are not whitespace or a comment
     */
    private function significantIndexes(array $tokens, int $from, int $to): array
    {
        $indexes = [];
        for ($index = $from; $index <= $to; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], self::IGNORABLE, true)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Drops a matched parentheses pair that wraps the whole value, and repeats while one remains.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $indexes Significant tokens of the value
     * @return array<int, int> The same indexes with wrapping parentheses removed
     */
    private function unwrap(array $tokens, array $indexes): array
    {
        while (count($indexes) >= 2 && $tokens[$indexes[0]] === '(' && $tokens[$indexes[count($indexes) - 1]] === ')') {
            if (!$this->parenWrapsAll($tokens, $indexes)) {
                break;
            }

            $indexes = array_values(array_slice($indexes, 1, -1));
        }

        return $indexes;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param array<int, int> $indexes Significant tokens, the first a `(` and the last a `)`
     * @return bool True when the opening parenthesis matches the last token and nothing past it
     */
    private function parenWrapsAll(array $tokens, array $indexes): bool
    {
        $depth = 0;
        $last = count($indexes) - 1;
        foreach ($indexes as $position => $index) {
            $token = $tokens[$index];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position === $last;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index inside the statement
     * @return int Index of the semicolon that ends it, or one past the last token when the file ends first
     */
    private function statementEnd(array $tokens, int $index): int
    {
        for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === ';') {
                return $cursor;
            }
        }

        return $cursor;
    }

    /**
     * @param string $class Fully qualified or aliased class name
     * @return string The segment after the last separator
     */
    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        $found = $this->significantIndex($tokens, $index, $step);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index, int $step): ?int
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], self::IGNORABLE, true)) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to look forward from
     * @return string|int|null Id of the next significant token, a character or a token constant
     */
    private function nextId(array $tokens, int $index): string|int|null
    {
        $next = $this->significantIndex($tokens, $index, 1);
        if ($next === null) {
            return null;
        }

        $token = $tokens[$next];

        return is_array($token) ? $token[0] : $token;
    }
}
