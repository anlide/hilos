<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Transaction\TestRollbackSqlGuard;
use Hilos\Tests\CodeStyle\Throws\CallResolver;
use Hilos\Tests\CodeStyle\Throws\ClassRecord;
use Hilos\Tests\CodeStyle\Throws\CrossFileRule;
use Hilos\Tests\CodeStyle\Throws\MethodRecord;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Judges explicit Hilos attributes, without loading any analyzed class.
 *
 * Follows indexed calls into test roots (self/parent/static, traits, static helpers and
 * declared receivers). Production callees retain their own transactions. Environment
 * preparation/release on unmarked bases is outside the case scope; calling those hooks
 * from a case still enters their bodies. Dynamic calls, free-function helpers, returned
 * receivers, trait adaptations and interprocess work are not a resolved call graph.
 *
 * The lexical body check includes callbacks. It recognizes Database transactionCommit,
 * manual scope entry, native PDO/mysqli construction, procedural mysqli calls and raw
 * transaction calls on native typed receivers. SQL is read only at known Database or
 * native sql/sqlRun/query/exec/prepare calls, from
 * literals, concatenations, preceding local assignments and literal class constants.
 * A known leading DDL/transaction verb is enough even with a dynamic suffix. Arbitrary
 * computed SQL and dynamic receiver types remain the runtime admission guard's work.
 */
final readonly class IntegrationTestRule implements CrossFileRule
{
    public const string ID = 'INTEGRATION-TEST';

    private const string MARK = 'Hilos\Testing\IntegrationTest';
    private const string BREAKS = 'Hilos\Testing\BreaksOrmState';
    private const string BASE = 'Hilos\Testing\RollbackIntegrationTestCase';
    private const string DATABASE = 'Hilos\Database\Database';
    private const array LIFECYCLE = ['setup', 'teardown', 'assertpreconditions', 'assertpostconditions', 'setupbeforeclass', 'teardownafterclass'];
    private const array ENVIRONMENT = ['prepareintegrationenvironment', 'releaseintegrationenvironment'];
    private const array CASE_HOOKS = ['setupintransaction', 'ormresettargets'];
    private const array RAW_TRANSACTION = ['commit', 'begintransaction', 'begin_transaction', 'autocommit', 'rollback'];
    private const array SQL_CALLS = ['sql', 'sqlrun', 'query', 'real_query', 'multi_query', 'exec', 'prepare'];
    private const array MEMBER_OPERATORS = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];

    /**
     * @param list<string> $testRoots Explicit suite roots, relative to the index base
     */
    public function __construct(private array $testRoots)
    {
    }

    /** @return string Stable report id */
    public function id(): string
    {
        return self::ID;
    }

    /** @return string Canonical integration-test contract */
    public function doc(): string
    {
        return 'docs/agents/testing/integration-tests.md';
    }

    /**
     * @param SourceIndex $index Backend and suite source index, with deliberate fixtures excluded
     * @return iterable<Violation> Attribute, lifecycle and rollback-boundary violations in source order
     */
    public function check(SourceIndex $index): iterable
    {
        $hits = [];
        foreach ($index->classes() as $class) {
            $marked = $this->hasAttribute($class->attributes, self::MARK);
            foreach ($class->attributes as $attribute) {
                if (!$marked && strcasecmp($attribute['name'], self::BREAKS) === 0) {
                    $hits[] = $this->hit($class, $attribute['line'], 'BreaksOrmState requires explicit IntegrationTest on its class');
                }
            }
            foreach ($class->methods as $method) {
                foreach ($method->attributes as $attribute) {
                    if (strcasecmp($attribute['name'], self::MARK) === 0) {
                        $hits[] = $this->hit($class, $attribute['line'], 'IntegrationTest is a class attribute, not a method attribute');
                    }
                    if (!$marked && strcasecmp($attribute['name'], self::BREAKS) === 0) {
                        $hits[] = $this->hit($class, $attribute['line'], 'BreaksOrmState requires explicit IntegrationTest on its class');
                    }
                }
            }
            if (!$marked) {
                continue;
            }
            if (!$this->extendsHarness($index, $class)) {
                $hits[] = $this->hit($class, $class->line, $class->shortName() . ' must extend RollbackIntegrationTestCase');
            }
            if ($class->isAbstract) {
                $hits[] = $this->hit($class, $class->line, 'IntegrationTest belongs on a concrete case, not an environment base');
            }
            array_push($hits, ...$this->checkCase($index, $class));
        }
        // A shared helper is one source occurrence even when several cases reach it.
        $unique = [];
        foreach ($hits as $hit) {
            $unique[$hit->describe($this->doc())] = $hit;
        }
        $hits = array_values($unique);
        usort($hits, static fn(Violation $a, Violation $b): int => [$a->relativePath, $a->line, $a->message]
            <=> [$b->relativePath, $b->line, $b->message]);

        yield from $hits;
    }

    /**
     * @param list<array{name: string, line: int}> $attributes Explicit attributes
     * @param string $name Qualified attribute name
     * @return bool Whether this declaration carries the attribute itself
     */
    private function hasAttribute(array $attributes, string $name): bool
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param SourceIndex $index Source declarations
     * @param ClassRecord $class Marked class
     * @return bool Whether its transitive parent chain reaches the rollback harness
     */
    private function extendsHarness(SourceIndex $index, ClassRecord $class): bool
    {
        $seen = [];
        while ($class->parent !== null && !isset($seen[strtolower($class->name)])) {
            if (strcasecmp($class->parent, self::BASE) === 0) {
                return true;
            }
            $seen[strtolower($class->name)] = true;
            $class = $index->find($class->parent);
            if ($class === null) {
                break;
            }
        }

        return false;
    }

    /**
     * @param SourceIndex $index Source declarations
     * @param ClassRecord $case Explicitly marked case
     * @return list<Violation> Findings in its methods and reachable test helpers
     */
    private function checkCase(SourceIndex $index, ClassRecord $case): array
    {
        $hits = [];
        $queue = [];
        $seenClasses = [];
        $overridden = [];
        $current = $case;
        while ($current !== null && strcasecmp($current->name, self::BASE) !== 0
            && !isset($seenClasses[strtolower($current->name)])) {
            $seenClasses[strtolower($current->name)] = true;
            foreach ([...array_values($current->methods), ...$index->traitMethods($current)] as $method) {
                $name = strtolower($method->name);
                if (isset($overridden[$name])) {
                    continue;
                }
                $overridden[$name] = true;
                $declaring = $index->find($method->class);
                if ($declaring === null) {
                    continue;
                }
                if (in_array($name, self::LIFECYCLE, true)
                    || ($current === $case && in_array($name, self::ENVIRONMENT, true))) {
                    $hits[] = $this->hit($declaring, $method->line, $method->name . '() overrides the managed integration lifecycle');
                }
                if ($current === $case || in_array($name, self::CASE_HOOKS, true)
                    || ($method->visibility === MethodRecord::VISIBILITY_PUBLIC && !in_array($name, self::ENVIRONMENT, true))) {
                    $queue[] = [$case, $method];
                }
            }
            $current = $current->parent === null ? null : $index->find($current->parent);
        }
        $resolver = new CallResolver($index);
        $visited = [];
        while ($queue !== []) {
            [$receiver, $method] = array_pop($queue);
            $key = strtolower($receiver->name . '::' . $method->class . '::' . $method->name);
            $declaring = $index->find($method->class);
            if (isset($visited[$key]) || $declaring === null || strcasecmp($declaring->name, self::BASE) === 0) {
                continue;
            }
            $visited[$key] = true;
            array_push($hits, ...$this->checkBody($index, $declaring, $method));
            foreach ($method->callSites as $site) {
                // parent is lexical; $this/static retain the concrete receiver for inherited helpers.
                $callee = $resolver->resolve($site->base === 'parent' ? $declaring : $receiver, $method, $site);
                $owner = $callee === null ? null : $index->find($callee->class);
                if ($owner !== null && $this->isTestLocal($owner->path)) {
                    $nextReceiver = in_array($site->base, ['$this', 'self', 'static', 'parent'], true) && $site->path === []
                        ? $receiver : $owner;
                    $queue[] = [$nextReceiver, $callee];
                }
            }
        }

        return $hits;
    }

    /**
     * @param string $path Path relative to the index base
     * @return bool Whether a callee belongs to a declared technical suite
     */
    private function isTestLocal(string $path): bool
    {
        foreach ($this->testRoots as $root) {
            if (str_starts_with($path, rtrim($root, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param SourceIndex $index Source declarations for literal constants
     * @param ClassRecord $class Declaring class, for lexical self/parent
     * @param MethodRecord $method Case method or reachable helper
     * @return list<Violation> Forbidden calls and known unsafe SQL
     */
    private function checkBody(SourceIndex $index, ClassRecord $class, MethodRecord $method): array
    {
        $hits = [];
        $locals = [];
        $types = $method->variableTypes;
        $tokens = [];
        $line = $method->bodyLine;
        foreach (token_get_all('<?php ' . $method->bodySource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $line += substr_count($token[1], "\n");
                    continue;
                }
                $at = $token[2] + $method->bodyLine - 1;
                $tokens[] = [$token[0], $token[1], $at];
                $line = $at + substr_count($token[1], "\n");
            } else {
                $tokens[] = [$token, $token, $line];
            }
        }
        foreach ($tokens as $cursor => $token) {
            if ($token[0] === T_VARIABLE && ($tokens[$cursor + 1][0] ?? null) === '=') {
                $locals[$token[1]] = $this->sqlValue($index, $class, $tokens, $cursor + 2, $locals);
                unset($types[$token[1]]);
                if (($tokens[$cursor + 2][0] ?? null) === T_NEW) {
                    $types[$token[1]] = $tokens[$cursor + 3][1] ?? null;
                }
            }
            if (!in_array($token[0], self::NAMES, true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            $before = $tokens[$cursor - 1][0] ?? null;
            $isCall = ($tokens[$cursor + 1][0] ?? null) === '(';
            if ($before === T_NEW && in_array($name, ['pdo', 'mysqli'], true)) {
                $hits[] = $this->hit($class, $token[2], 'Native ' . $token[1] . ' bypasses the rollback scope');
                continue;
            }
            if (!$isCall) {
                continue;
            }
            $member = in_array($before, self::MEMBER_OPERATORS, true);
            if (!$member && str_starts_with($name, 'mysqli_')) {
                $hits[] = $this->hit($class, $token[2], $token[1] . '() bypasses the rollback scope');
                continue;
            }
            if (!$member) {
                continue;
            }
            if ($name === 'beginrollbackscopefortest') {
                $hits[] = $this->hit($class, $token[2], 'beginRollbackScopeForTest() is owned by the integration lifecycle');
            } elseif ($name === 'transactioncommit'
                && $this->receiverType($class, $tokens, $cursor, $types) === strtolower(self::DATABASE)) {
                $hits[] = $this->hit($class, $token[2], 'Explicit transactionCommit() is forbidden in an integration case or helper');
            } elseif (in_array($name, self::RAW_TRANSACTION, true)
                && in_array($this->receiverType($class, $tokens, $cursor, $types), ['pdo', 'mysqli'], true)) {
                $hits[] = $this->hit($class, $token[2], $token[1] . '() is raw transaction control outside the integration lifecycle');
            } elseif (in_array($this->receiverType($class, $tokens, $cursor, $types), ['pdo', 'mysqli'], true)) {
                $hits[] = $this->hit($class, $token[2], $token[1] . '() on a native connection bypasses the rollback scope');
            } elseif (in_array($name, self::SQL_CALLS, true)
                && $this->receiverType($class, $tokens, $cursor, $types) === strtolower(self::DATABASE)) {
                $sqlStart = $cursor + 2;
                if (isset($tokens[$sqlStart]) && strtolower($tokens[$sqlStart][1]) === 'sql'
                    && ($tokens[$sqlStart + 1][0] ?? null) === ':') {
                    $sqlStart += 2;
                }
                $sql = $this->sqlValue($index, $class, $tokens, $sqlStart, $locals);
                if ($sql !== null && $this->unsafeSql($sql)) {
                    $hits[] = $this->hit($class, $token[2], 'Executed SQL leaves the rollback-safe statement families');
                }
            }
        }

        return $hits;
    }

    /**
     * @param ClassRecord $class Declaring class
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens Significant body tokens
     * @param int $cursor Called member name
     * @param array<string, ?string> $types Declared parameters and direct local constructions
     * @return ?string Known lowercased receiver class; null for computed or undeclared receivers
     */
    private function receiverType(ClassRecord $class, array $tokens, int $cursor, array $types): ?string
    {
        $receiver = $tokens[$cursor - 2] ?? null;
        if ($receiver === null) {
            return null;
        }
        if (($tokens[$cursor - 1][0] ?? null) === T_DOUBLE_COLON && in_array($receiver[0], self::NAMES, true)) {
            return strtolower($receiver[1]);
        }
        if ($receiver[0] === T_VARIABLE && !in_array($tokens[$cursor - 3][0] ?? null, self::MEMBER_OPERATORS, true)) {
            return isset($types[$receiver[1]]) ? strtolower($types[$receiver[1]]) : null;
        }
        if (($tokens[$cursor - 4][1] ?? null) === '$this' && ($tokens[$cursor - 3][0] ?? null) === T_OBJECT_OPERATOR) {
            return isset($class->propertyTypes[$receiver[1]]) ? strtolower($class->propertyTypes[$receiver[1]]) : null;
        }

        return null;
    }

    /**
     * This is a source expression reader, not PHP evaluation or cross-method data flow.
     * An unknown suffix keeps only a known leading forbidden SQL verb useful to the caller.
     *
     * @param SourceIndex $index Constant declarations
     * @param ClassRecord $class Lexical class
     * @param list<array{0: int|string, 1: string, 2: int}> $tokens Body or constant tokens
     * @param int $cursor First expression token
     * @param array<string, ?string> $locals Preceding literal local assignments
     * @param list<string> $seen Constants already expanded, guarding cycles
     * @return ?string Known SQL text, or a known unsafe prefix; null for an unresolved expression
     */
    private function sqlValue(SourceIndex $index, ClassRecord $class, array $tokens, int $cursor, array $locals, array $seen = []): ?string
    {
        $parts = [];
        while (isset($tokens[$cursor])) {
            $token = $tokens[$cursor];
            $part = null;
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $part = $token[1][0] === "'"
                    ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($token[1], 1, -1))
                    : stripcslashes(substr($token[1], 1, -1));
            } elseif ($token[0] === T_VARIABLE) {
                $part = $locals[$token[1]] ?? null;
            } elseif ($token[0] === T_START_HEREDOC && ($tokens[$cursor + 1][0] ?? null) === T_ENCAPSED_AND_WHITESPACE
                && ($tokens[$cursor + 2][0] ?? null) === T_END_HEREDOC) {
                $part = $tokens[$cursor + 1][1];
                $cursor += 2;
            } elseif (in_array($token[0], self::NAMES, true) && ($tokens[$cursor + 1][0] ?? null) === T_DOUBLE_COLON) {
                $owner = match (strtolower($token[1])) {
                    'self', 'static' => $class->name,
                    'parent' => $class->parent,
                    default => $token[1],
                };
                $constant = $tokens[$cursor + 2][1] ?? null;
                $key = $owner . '::' . $constant;
                if ($owner !== null && $constant !== null && !in_array($key, $seen, true)) {
                    $value = $index->resolveConstant($owner, $constant);
                    if ($value !== null) {
                        $raw = token_get_all('<?php ' . $value);
                        $significant = [];
                        foreach ($raw as $piece) {
                            if (is_array($piece) && in_array($piece[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                continue;
                            }
                            $significant[] = is_array($piece) ? $piece : [$piece, $piece, $token[2]];
                        }
                        $part = $this->sqlValue($index, $index->find($owner) ?? $class, $significant, 0, [], [...$seen, $key]);
                    }
                }
                $cursor += 2;
            }
            if ($part === null) {
                $prefix = implode('', $parts);

                return preg_match('/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|COMMIT|ROLLBACK|BEGIN|START|SET|LOCK|UNLOCK)\b/i', $prefix) === 1
                    ? $prefix : null;
            }
            $parts[] = $part;
            $cursor++;
            if (($tokens[$cursor][0] ?? null) !== '.') {
                return in_array($tokens[$cursor][0] ?? null, [',', ')', ';', null], true) ? implode('', $parts) : null;
            }
            $cursor++;
        }

        return null;
    }

    /**
     * Reuses the scope's SQL lexer for complete literals: quoted DDL data is not a statement.
     * The session mode is unknown here: report only SQL refused under every supported lexical
     * mode. Runtime admission still checks the actual connection. No analyzed class is loaded.
     *
     * @param string $sql Known source SQL
     * @return bool Whether the query is forbidden regardless of the session's lexical mode
     */
    private function unsafeSql(string $sql): bool
    {
        foreach ([true, false] as $backslashEscapes) {
            foreach ([false, true] as $ansiQuotes) {
                try {
                    TestRollbackSqlGuard::check($sql, $backslashEscapes, $ansiQuotes);

                    return false;
                } catch (DatabaseRuntimeException) {
                    // Another session mode may admit the same literal; the source cannot decide it.
                }
            }
        }

        return true;
    }

    /**
     * @param ClassRecord $class Source owner
     * @param int $line Source occurrence
     * @param string $message Contract violation
     * @return Violation Finding addressed from the index base
     */
    private function hit(ClassRecord $class, int $line, string $message): Violation
    {
        return new Violation(self::ID, $class->path, $line, $message);
    }
}
