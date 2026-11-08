<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\MomentStorage;

use Hilos\Database\Database;
use Hilos\Tests\CodeStyle\SourceScanner;
use Hilos\Tests\CodeStyle\Throws\ClassRecord;
use Hilos\Tests\CodeStyle\Throws\MethodRecord;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;

/**
 * Bounded proof of direct PHP/SQL writes to catalogued moments; silence is not a writer audit.
 * No analyzed class is loaded. Unknown receivers, dynamic members, helper calls and general
 * integer dataflow remain outside this rule. Local bindings stop at control-flow boundaries;
 * nested callbacks and anonymous classes have their own scope and are not inferred here.
 * SQL proof is limited to Database::sql/sqlRun with a static INSERT column list or UPDATE SET.
 * Literal concatenations/constants and an inline parameter array are read without executing them;
 * dynamic templates, table aliases/joins, helper-produced SQL and ambiguous tables are unknown.
 */
final class MomentStorageSourceRule
{
    public const string ID = 'MOMENT-WRITE';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];
    private const array CASTS = [T_INT_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_OBJECT_CAST, T_UNSET_CAST];
    private const array CONTROL = [
        T_IF, T_ELSE, T_ELSEIF, T_SWITCH, T_CASE, T_DEFAULT, T_FOR, T_FOREACH, T_WHILE, T_DO,
        T_TRY, T_CATCH, T_FINALLY, T_FUNCTION, T_FN, T_MATCH, T_GOTO, T_RETURN, T_THROW, T_UNSET,
    ];
    private const array MUTATIONS = [
        '=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_CONCAT_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC,
    ];
    private const string TIME_HELPER = TimeHelper::class;

    /** @var array<string, array<int, list<string>>> __set parameters, keyed by source path and declaration line */
    private array $setterParameters = [];

    /** @var array<string, array<int, array<string, int>>> Property visibility tokens (T_STATIC for static slots), by path and class line */
    private array $declaredProperties = [];

    /**
     * The list preserves distinct declarations even when physical table names coincide.
     * Entity is the concrete receiver class (null for a provider); declaration owns the schema subject.
     * Mapped controls PHP sinks only; SQL can also write DB-only columns. An explicit project storage
     * choice exempts only project-owned moments. The caller supplies validated MomentColumns metadata.
     *
     * @param SourceIndex $index Existing token index, including receiver declarations
     * @param list<array{entity: ?string, declaration: string, table: string, column: string,
     *     mapped: bool, frameworkOwned: bool, projectStorage: ?string}> $catalog Normalized semantic columns
     */
    public function __construct(private readonly SourceIndex $index, private readonly array $catalog)
    {
    }

    /**
     * Sources supply lexical function imports/shadows and local @var aliases missing from the shared index.
     * The caller owns root selection/exclusions; production discovery and complete-domain coverage are separate.
     *
     * @param string $base Repository or fixture base
     * @param list<string> $roots Roots relative to base
     * @param list<array{entity: ?string, declaration: string, table: string, column: string,
     *     mapped: bool, frameworkOwned: bool, projectStorage: ?string}> $catalog Validated semantic columns
     * @param array<string, list<string>> $excludedPaths Exact exclusions keyed by root
     * @return array{reported: array<string, list<string>>, subjects: list<string>} Exact findings and proven current sink subjects
     */
    public static function forRoots(string $base, array $roots, array $catalog, array $excludedPaths = []): array
    {
        $sources = [];
        foreach ($roots as $root) {
            if (!is_dir($base . '/' . $root) || !is_readable($base . '/' . $root)) {
                throw new RuntimeException('Unreadable moment source root: ' . $root);
            }
            $scanner = new SourceScanner($base . '/' . $root, $excludedPaths[$root] ?? []);
            foreach ($scanner->files() as $file) {
                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    throw new RuntimeException('Unreadable moment source: ' . $file->getPathname());
                }
                $sources[$root . '/' . $scanner->relativePath($file)] = $source;
            }
        }

        return (new self(SourceIndex::forRoots($base, $roots, $excludedPaths), $catalog))->analyze($sources);
    }

    /**
     * @param array<string, string> $sources PHP text keyed by the same relative paths as the index
     * @return array{reported: array<string, list<string>>, subjects: list<string>} Canonical baseline input, sorted by identity
     */
    public function analyze(array $sources): array
    {
        $contexts = [];
        $functions = [];
        $this->setterParameters = [];
        $this->declaredProperties = [];
        foreach ($sources as $path => $source) {
            $contexts[$path] = $this->fileContext($source);
            $functions += $contexts[$path]['functions'];
            $this->setterParameters[$path] = $contexts[$path]['setters'];
            $this->declaredProperties[$path] = $contexts[$path]['properties'];
        }
        $reported = [];
        $subjects = [];
        foreach ($this->index->classes() as $class) {
            foreach ($class->methods as $method) {
                if (!$method->hasBody || !isset($contexts[$class->path])) {
                    continue;
                }
                $context = $contexts[$class->path]['declarations'][$class->line] ?? null;
                if ($context !== null) {
                    $this->checkMethod($class, $method, $context, $functions, $reported, $subjects);
                }
            }
        }
        ksort($reported, SORT_STRING);
        ksort($subjects, SORT_STRING);

        return ['reported' => $reported, 'subjects' => array_keys($subjects)];
    }

    /**
     * @param ClassRecord $class Declaring class
     * @param MethodRecord $method Indexed lexical body
     * @param array $context File imports and namespace
     * @param array<string, true> $functions Declared free functions, lowercased
     * @param array<string, list<string>> $reported Accumulated occurrences
     * @param array<string, true> $subjects Proven sinks, including valid writes
     */
    private function checkMethod(
        ClassRecord $class,
        MethodRecord $method,
        array $context,
        array $functions,
        array &$reported,
        array &$subjects,
    ): void {
        $tokens = $this->tokens('<?php ' . $method->bodySource, true);
        $types = $method->variableTypes;
        $aliases = [];
        $classNames = [];
        $assignments = [];
        $annotatedAssignments = [];
        $scopes = $this->methodScopes($class, $method);
        for ($cursor = 0; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            // PHP permits keywords as argument names; their lexical role ends at this colon.
            if ($token[0] !== T_DOC_COMMENT
                && $this->namedArgumentDelimiter($tokens, $this->codeTokenPosition($tokens, $cursor + 1))) {
                continue;
            }
            if (in_array($token[0], [T_FUNCTION, T_FN], true)
                || ($token[0] === T_CLASS && ($tokens[$cursor - 1][0] ?? null) !== T_DOUBLE_COLON)) {
                $aliases = [];
                $classNames = [];
                // Never borrow outer parameter types for a callback's shadowing parameters.
                $end = $cursor;
                while (isset($tokens[$end]) && !in_array($tokens[$end][1], ['{', '=>', ';'], true)) {
                    if ($tokens[$end][1] === '(') {
                        $end = $this->matching($tokens, $end);
                    }
                    $end++;
                }
                $cursor = ($tokens[$end][1] ?? null) === '{'
                    ? $this->matching($tokens, $end) : $this->expressionEnd($tokens, $end + 1) - 1;
                continue;
            }
            if ($token[0] === T_DOC_COMMENT) {
                if (preg_match('/@var\s+(\S+)(?:\s+(\$\w+))?/', $token[1], $match) === 1) {
                    $written = array_values(array_filter(explode('|', ltrim($match[1], '?')),
                        static fn(string $part): bool => strcasecmp($part, 'null') !== 0));
                    $variable = $match[2] ?? (($tokens[$cursor + 1][0] ?? null) === T_VARIABLE ? $tokens[$cursor + 1][1] : null);
                    if (count($written) === 1 && $variable !== null) {
                        $types[$variable] = $this->resolveName($written[0], $class, $context['classes']);
                        if (($tokens[$cursor + 1][1] ?? null) === $variable && ($tokens[$cursor + 2][1] ?? null) === '=') {
                            $annotatedAssignments[$cursor + 1] = $types[$variable];
                        }
                    }
                }
                continue;
            }
            // A named argument separator is not a control-flow boundary.
            if (in_array($token[0], self::CONTROL, true) || in_array($token[1], ['{', '}', '?', '&&', '||'], true)
                || ($token[1] === ':' && !$this->namedArgumentDelimiter($tokens, $cursor))) {
                $aliases = [];
                $classNames = [];
                $types = array_intersect_key($types, $method->variableTypes);
            }
            if (in_array($token[0], [T_FOREACH, T_CATCH], true) && ($tokens[$cursor + 1][1] ?? null) === '(') {
                $close = $this->matching($tokens, $cursor + 1);
                $binding = $token[0] === T_CATCH;
                for ($header = $cursor + 2; $header < $close; $header++) {
                    $binding = $binding || $tokens[$header][0] === T_AS;
                    if ($binding && $tokens[$header][0] === T_VARIABLE) {
                        unset($types[$tokens[$header][1]]);
                    }
                }
            }

            if (in_array($token[0], self::NAMES, true) && ($tokens[$cursor + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$cursor + 2][0] ?? null) === T_STRING && ($tokens[$cursor + 3][1] ?? null) === '('
                && strcasecmp($this->className($token[1], $class), Database::class) === 0
                && in_array(strtolower($tokens[$cursor + 2][1]), ['sql', 'sqlrun'], true)) {
                $this->sqlWrites($tokens, $cursor, $class, $method, $aliases, $types, $context, $functions, $reported, $subjects);
            }

            // Only a standalone local assignment binds a value; nested assignments are not dataflow.
            if ($token[0] === T_VARIABLE && in_array($tokens[$cursor + 1][0] ?? null, self::MUTATIONS, true)) {
                $variable = $token[1];
                unset($types[$variable], $aliases[$variable], $classNames[$variable]);
                if (isset($annotatedAssignments[$cursor])) {
                    $types[$variable] = $annotatedAssignments[$cursor];
                }
                $assignments[$variable] = ($assignments[$variable] ?? 0) + 1;
                if (($tokens[$cursor + 1][1] ?? null) === '=' && $this->statementStart($tokens, $cursor)) {
                    $end = $this->expressionEnd($tokens, $cursor + 2);
                    $rhs = array_slice($tokens, $cursor + 2, $end - $cursor - 2);
                    if ($assignments[$variable] === 1) {
                        $type = $this->receiverType($rhs, $class, $types, $classNames);
                        if ($type !== null) {
                            $types[$variable] = $type;
                        }
                        $constant = $this->classValue($rhs, $class, $types, $classNames);
                        if ($constant !== null) {
                            $classNames[$variable] = $constant;
                        }
                        if ($this->badRhs($rhs, $class, $types, $context, $functions)) {
                            $aliases[$variable] = array_slice($tokens, $cursor, $end - $cursor);
                        }
                    }
                }
            }
            if ($token[0] === T_VARIABLE && in_array($tokens[$cursor - 1][0] ?? null, [T_INC, T_DEC], true)) {
                unset($aliases[$token[1]], $types[$token[1]], $classNames[$token[1]]);
                $assignments[$token[1]] = ($assignments[$token[1]] ?? 0) + 1;
            }
            if ($token[1] === '=') {
                $start = $this->assignmentStart($tokens, $cursor);
                $lhs = array_slice($tokens, $start, $cursor - $start);
                foreach ($this->destructuredVariables($lhs) as $variable) {
                    unset($types[$variable], $aliases[$variable], $classNames[$variable]);
                    $assignments[$variable] = ($assignments[$variable] ?? 0) + 1;
                }
                $end = $this->expressionEnd($tokens, $cursor + 1);
                $rhs = array_slice($tokens, $cursor + 1, $end - $cursor - 1);
                $columns = [];
                foreach ($scopes as $scope) {
                    array_push($columns, ...$this->propertySinks($lhs, $scope, $types, $classNames));
                }
                foreach (array_unique($columns, SORT_REGULAR) as $column) {
                    $this->write($column, $class, $method, $tokens, $start, $end, $rhs, $aliases, $types, $context,
                        $functions, $reported, $subjects);
                }
            }
            // fromRow() is the Entity's row entry; Entity::get([...]) and free arrays are reads/data.
            if (in_array($token[0], self::NAMES, true) && ($tokens[$cursor + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$cursor + 2][0] ?? null) === T_STRING && strcasecmp($tokens[$cursor + 2][1], 'fromRow') === 0
                && ($tokens[$cursor + 3][1] ?? null) === '(' && ($tokens[$cursor + 4][1] ?? null) === '[') {
                $entity = $this->className($token[1], $class);
                $close = $this->matching($tokens, $cursor + 4);
                foreach ($this->split(array_slice($tokens, $cursor + 5, $close - $cursor - 5), ',') as $entry) {
                    $arrow = $this->topLevel($entry, '=>');
                    if ($arrow === null) {
                        continue;
                    }
                    $columnName = $this->stringValue(array_slice($entry, 0, $arrow), $class);
                    foreach ($this->entityColumns($entity, $columnName) as $column) {
                        $writeTokens = [...array_slice($tokens, $cursor, 5), ...$entry, $tokens[$close], $tokens[$close + 1]];
                        $this->write($column, $class, $method, $writeTokens, 0, count($writeTokens),
                            array_slice($entry, $arrow + 1), $aliases, $types, $context, $functions, $reported, $subjects);
                    }
                }
            }
        }
    }

    /**
     * @param array $column Normalized catalog column
     * @param ClassRecord $class Declaring class
     * @param MethodRecord $method Declaring method
     * @param array $tokens Significant method or row-entry write tokens
     * @param int $start First write token
     * @param int $end First token after the write
     * @param array $rhs Written expression
     * @param array $aliases Simple bad RHS definitions
     * @param array $types Proven local receiver types
     * @param array $context Lexical name context
     * @param array $functions Free-function declarations
     * @param array $reported Exact finding occurrences
     * @param array $subjects Proven source subjects
     * @param bool $sql Whether the RHS is SQL tokens rather than a PHP parameter/property expression
     */
    private function write(
        array $column, ClassRecord $class, MethodRecord $method, array $tokens, int $start, int $end,
        array $rhs, array $aliases, array $types, array $context, array $functions, array &$reported, array &$subjects,
        bool $sql = false,
    ): void {
        $schema = $this->json(['schema', $column['declaration'], $column['table'], $column['column']]);
        $subject = $this->json(['source', $class->path, $class->name . '::' . $method->name, $schema]);
        $subjects[$subject] = true;
        $unwrapped = $this->unwrap($rhs);
        $alias = !$sql && count($unwrapped) === 1 ? ($aliases[$unwrapped[0][1]] ?? null) : null;
        if ($alias === null && !($sql ? $this->badSqlRhs($rhs) : $this->badRhs($rhs, $class, $types, $context, $functions))) {
            return;
        }
        $expression = $this->withoutDocs(array_slice($tokens, $start, $end - $start));
        // The initializer participates too: replacing an aliased RHS must not reuse historical debt.
        $fingerprintTokens = $alias === null ? $expression : [...$expression, ...$this->withoutDocs($alias)];
        $key = $this->json([self::ID, $subject, hash('sha256', $this->json(array_column($fingerprintTokens, 1)))]);
        $fragment = implode(' ', array_column($expression, 1));
        if ($alias !== null) {
            $fragment .= ' [from ' . implode(' ', array_column($this->withoutDocs($alias), 1)) . ']';
        }
        $reported[$key][] = sprintf('%s:%d %s %s::%s -> %s.%s: %s', $class->path,
            $method->bodyLine + $tokens[$start][2] - 1, self::ID, $class->name, $method->name,
            $column['table'], $column['column'], $fragment);
    }

    /**
     * @param array $tokens Method tokens
     * @param int $start Known Database call
     * @param ClassRecord $class Declaring class
     * @param MethodRecord $method Declaring method
     * @param array $aliases Simple PHP RHS bindings
     * @param array $types Local receiver types
     * @param array $context Lexical name context
     * @param array $functions Free-function declarations
     * @param array $reported Canonical occurrences
     * @param array $subjects Proven sinks, including valid SQL writes
     */
    private function sqlWrites(
        array $tokens, int $start, ClassRecord $class, MethodRecord $method, array $aliases, array $types,
        array $context, array $functions, array &$reported, array &$subjects,
    ): void {
        $close = $this->matching($tokens, $start + 3);
        $arguments = $this->split(array_slice($tokens, $start + 4, $close - $start - 4), ',');
        $named = [];
        $templateArgument = null;
        foreach ($arguments as $position => $argument) {
            if ($argument === []) {
                continue;
            }
            $name = ($argument[1][1] ?? null) === ':' ? $argument[0][1] : ($position === 0 ? 'sql' : ($position === 1 ? 'params' : null));
            if ($name === null) {
                continue;
            }
            if (isset($named[$name])) {
                return;
            }
            $named[$name] = ($argument[1][1] ?? null) === ':' ? array_slice($argument, 2) : $argument;
            if ($name === 'sql') {
                $templateArgument = $position;
            }
        }
        $template = '';
        foreach ($this->split($this->unwrap($named['sql'] ?? []), '.') as $part) {
            $literal = $this->stringValue($part, $class);
            if ($literal === null) {
                return;
            }
            $template .= $literal;
        }
        $sql = $this->sqlTokens($template);
        if ($sql === null || $templateArgument === null) {
            return;
        }
        $sinks = $this->sqlSinks($sql);
        $bindings = $this->sqlBindings($named['params'] ?? [], $sql);
        // Evaluation order inside a mutating call would need more than the one-local-alias proof.
        if (array_any(array_slice($tokens, $start + 4, $close - $start - 4),
            static fn(array $token): bool => in_array($token[0], self::MUTATIONS, true))) {
            $bindings = [];
        }
        // Canonical SQL tokens replace the PHP template spelling; SQL comments/spacing carry no debt.
        $expression = array_slice($tokens, $start, 4);
        foreach ($arguments as $position => $argument) {
            if ($argument === []) {
                continue;
            }
            if ($position > 0) {
                $expression[] = [',', ',', $tokens[$start][2]];
            }
            if ($position === $templateArgument) {
                if (($argument[1][1] ?? null) === ':') {
                    array_push($expression, ...array_slice($argument, 0, 2));
                }
                array_push($expression, ...$sql);
            } else {
                array_push($expression, ...$argument);
            }
        }
        $expression[] = $tokens[$close];
        foreach ($sinks as [$table, $columnName, $rhs]) {
            $rhs = $this->unwrapSql($rhs);
            $parameter = count($rhs) === 1 && in_array($rhs[0][0], ['positional', 'named'], true);
            if ($parameter) {
                $rhs = $bindings[$rhs[0][0] === 'positional' ? $rhs[0][3] : substr($rhs[0][1], 1)] ?? [];
            }
            foreach ($this->sqlColumns($table, $columnName) as $column) {
                $this->write($column, $class, $method, $expression, 0, count($expression), $rhs,
                    $aliases, $types, $context, $functions, $reported, $subjects, !$parameter);
            }
        }
    }

    /**
     * This small lexer reads only an already-proven static template, never raw PHP source.
     * Executable SQL comments and unclosed/unknown lexical forms invalidate the template.
     *
     * @param string $sql Static template
     * @return ?array Significant SQL tokens; positional placeholders retain their global ordinal
     */
    private function sqlTokens(string $sql): ?array
    {
        $tokens = [];
        $ordinal = 0;
        $pattern = '~\G(?:\s+|--(?=\s)[^\r\n]*|\#[^\r\n]*|/\*(?![!]|M!)[\s\S]*?\*/'
            . '|(?<quoted>\x27(?:\\\\[\s\S]|\x27\x27|[^\x27\\\\])*\x27|"(?:\\\\[\s\S]|""|[^"\\\\])*")'
            . '|(?<identifier>`(?:``|[^`])*`)|(?<named>:[a-zA-Z_][a-zA-Z_0-9]*)'
            . '|(?<word>[a-zA-Z_][a-zA-Z_0-9$]*)|(?<number>\d+(?:\.\d+)?)|(?<symbol>[(),.;?=+*/%<>!|&^\x7e-]))~';
        for ($offset = 0; $offset < strlen($sql); $offset += strlen($match[0])) {
            if (substr($sql, $offset, 2) === '/*'
                && (substr($sql, $offset + 2, 1) === '!' || strtoupper(substr($sql, $offset + 2, 2)) === 'M!'
                    || strpos($sql, '*/', $offset + 2) === false)) {
                return null;
            }
            if (preg_match($pattern, $sql, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                return null;
            }
            $kind = null;
            foreach (['quoted', 'identifier', 'named', 'word', 'number', 'symbol'] as $candidate) {
                if ($match[$candidate] !== null) {
                    $kind = $candidate;
                    break;
                }
            }
            if ($kind !== null) {
                $token = [$kind, $match[0], 1];
                if ($match[0] === '?') {
                    $token[0] = 'positional';
                    $token[3] = $ordinal++;
                }
                $tokens[] = $token;
            }
        }
        if (($tokens[array_key_last($tokens)][1] ?? null) === ';') {
            array_pop($tokens);
        }
        if (array_any($tokens, static fn(array $token): bool => $token[1] === ';')) {
            return null;
        }

        return $tokens;
    }

    /**
     * @param array $sql Significant SQL tokens
     * @return list<array{string, string, array}> Single-table INSERT [IGNORE] VALUES, optional duplicate assignments, or UPDATE SET sinks
     */
    private function sqlSinks(array $sql): array
    {
        $insert = $this->sqlWord($sql[0] ?? null) === 'INSERT';
        $update = $this->sqlWord($sql[0] ?? null) === 'UPDATE';
        if (!$insert && !$update) {
            return [];
        }
        $cursor = 1;
        if ($insert) {
            if ($this->sqlWord($sql[$cursor] ?? null) === 'IGNORE') {
                $cursor++;
            }
            if ($this->sqlWord($sql[$cursor++] ?? null) !== 'INTO') {
                return [];
            }
        }
        $table = $this->sqlIdentifier(array_slice($sql, $cursor++, 1));
        if (($sql[$cursor][1] ?? null) === '.') {
            $qualifier = $table;
            $table = $this->sqlIdentifier(array_slice($sql, $cursor + 1, 1));
            $table = $qualifier === null || $table === null ? null : $qualifier . '.' . $table;
            $cursor += 2;
        }
        if ($table === null) {
            return [];
        }
        $sinks = [];
        if ($insert) {
            if (($sql[$cursor][1] ?? null) !== '(') {
                return [];
            }
            $close = $this->matching($sql, $cursor);
            $columns = array_map($this->sqlIdentifier(...), $this->split(array_slice($sql, $cursor + 1, $close - $cursor - 1), ','));
            if (in_array(null, $columns, true) || count(array_unique($columns)) !== count($columns)
                || $this->sqlWord($sql[$close + 1] ?? null) !== 'VALUES') {
                return [];
            }
            $cursor = $close + 2;
            while (($sql[$cursor][1] ?? null) === '(') {
                $close = $this->matching($sql, $cursor);
                $values = $this->split(array_slice($sql, $cursor + 1, $close - $cursor - 1), ',');
                if (count($values) !== count($columns)) {
                    return [];
                }
                foreach ($columns as $position => $column) {
                    $sinks[] = [$table, $column, $values[$position]];
                }
                $cursor = $close + 1;
                if ($cursor === count($sql)) {
                    return $sinks;
                }
                if (($sql[$cursor][1] ?? null) !== ',') {
                    break;
                }
                if (($sql[++$cursor][1] ?? null) !== '(') {
                    return [];
                }
            }
            if ($sinks === [] || array_map($this->sqlWord(...), array_slice($sql, $cursor, 4)) !== ['ON', 'DUPLICATE', 'KEY', 'UPDATE']) {
                return [];
            }
            $cursor += 4;
        } elseif ($this->sqlWord($sql[$cursor++] ?? null) !== 'SET') {
            return [];
        }
        $end = count($sql);
        for ($position = $cursor; $position < count($sql); $position++) {
            if ($sql[$position][1] === '(') {
                $position = $this->matching($sql, $position);
            } elseif ($update && $sql[$position][0] === 'word' && in_array(strtoupper($sql[$position][1]), ['WHERE', 'ORDER', 'LIMIT'], true)) {
                $end = $position;
                break;
            }
        }
        foreach ($this->split(array_slice($sql, $cursor, $end - $cursor), ',') as $assignment) {
            $column = $this->sqlIdentifier(array_slice($assignment, 0, 1));
            if ($column === null || ($assignment[1][1] ?? null) !== '=' || count($assignment) < 3) {
                return [];
            }
            $sinks[] = [$table, $column, array_slice($assignment, 2)];
        }

        return $sinks;
    }

    /**
     * @param ?array $token SQL token, absent at end of input
     * @return ?string Uppercase unquoted word, null for quoted names/literals or missing tokens
     */
    private function sqlWord(?array $token): ?string
    {
        return $token !== null && $token[0] === 'word' ? strtoupper($token[1]) : null;
    }

    /**
     * @param array $tokens Single identifier tokens
     * @return ?string Exact identifier, with SQL backtick escaping removed
     */
    private function sqlIdentifier(array $tokens): ?string
    {
        if (count($tokens) !== 1 || !in_array($tokens[0][0], ['word', 'identifier'], true)) {
            return null;
        }

        return $tokens[0][0] === 'identifier' ? str_replace('``', '`', substr($tokens[0][1], 1, -1)) : $tokens[0][1];
    }

    /**
     * Candidate declarations are counted before opt-out filtering, so a project choice cannot
     * make an ambiguous physical name look unique. Concrete mounts sharing one declaration
     * are one schema subject. One concrete Entity can also carry columns of several declaring
     * ancestors; unrelated project receivers or providers with different declarations are ambiguous.
     *
     * @param string $table Literal physical name
     * @param string $column Literal column
     * @return array Unambiguous standard moments, including providers and DB-only columns
     */
    private function sqlColumns(string $table, string $column): array
    {
        $declarations = [];
        $entities = [];
        $columns = [];
        foreach ($this->catalog as $entry) {
            if ($entry['table'] !== $table) {
                continue;
            }
            $declarations[$entry['declaration']] = true;
            $entities[$this->json([$entry['entity']])] = $entry['entity'];
            if (strcasecmp($entry['column'], $column) === 0 && ($entry['projectStorage'] === null || $entry['frameworkOwned'])) {
                $columns[$this->json(['schema', $entry['declaration'], $table, $entry['column']])] = $entry;
            }
        }

        return count($declarations) === 1 || (count($entities) === 1 && reset($entities) !== null) ? array_values($columns) : [];
    }

    /**
     * Only a complete inline list/map with exact placeholder coverage is bound. This is a
     * syntactic binding check, not a validation of a driver's supported parameter API.
     *
     * @param array $expression PHP inline parameter array
     * @param array $sql Complete SQL template, including non-moment and WHERE placeholders
     * @return array<int|string, array> PHP RHS tokens by positional ordinal or named key
     */
    private function sqlBindings(array $expression, array $sql): array
    {
        $expression = $this->unwrap($expression);
        if (($expression[0][0] ?? null) === T_ARRAY && ($expression[1][1] ?? null) === '(') {
            $expression = array_slice($expression, 1);
        }
        if (!in_array($expression[0][1] ?? null, ['[', '('], true) || $this->matching($expression, 0) !== count($expression) - 1) {
            return [];
        }
        $bindings = [];
        foreach ($this->split(array_slice($expression, 1, -1), ',') as $entry) {
            if ($entry === []) {
                continue;
            }
            if (($entry[0][0] ?? null) === T_ELLIPSIS
                || array_any($entry, static fn(array $token): bool => in_array($token[0], self::MUTATIONS, true))) {
                return [];
            }
            $arrow = $this->topLevel($entry, '=>');
            if ($arrow === null) {
                $bindings[] = $entry;
                continue;
            }
            $keyTokens = array_slice($entry, 0, $arrow);
            $key = $this->literalString($keyTokens);
            if ($key !== null) {
                $key = str_starts_with($key, ':') ? substr($key, 1) : $key;
            } elseif (count($keyTokens) === 1 && $keyTokens[0][0] === T_LNUMBER && (string)(int)$keyTokens[0][1] === $keyTokens[0][1]) {
                $key = (int)$keyTokens[0][1];
            } else {
                return [];
            }
            if (array_key_exists($key, $bindings)) {
                return [];
            }
            $bindings[$key] = array_slice($entry, $arrow + 1);
        }
        $expected = [];
        $kinds = [];
        foreach ($sql as $token) {
            if (in_array($token[0], ['positional', 'named'], true)) {
                $expected[$token[0] === 'positional' ? $token[3] : substr($token[1], 1)] = true;
                $kinds[$token[0]] = true;
            }
        }
        if (count($kinds) !== 1 || count($bindings) !== count($expected) || array_diff_key($expected, $bindings) !== []
            || (isset($kinds['positional']) && !array_is_list($bindings))) {
            return [];
        }

        return $bindings;
    }

    /**
     * Arithmetic is deliberately opaque: a journal's integer epoch-ms formula is not bare seconds.
     *
     * @param array $rhs Complete SQL RHS tokens
     * @return bool Whether the complete expression proves SQL datetime or bare Unix seconds
     */
    private function badSqlRhs(array $rhs): bool
    {
        $rhs = $this->unwrapSql($rhs);
        if (count($rhs) === 1 && $rhs[0][0] === 'quoted') {
            $literal = substr($rhs[0][1], 1, -1);

            return preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?\z/', $literal) === 1;
        }
        $name = $this->sqlWord($rhs[0] ?? null);
        if (!in_array($name, ['NOW', 'CURRENT_TIMESTAMP', 'UNIX_TIMESTAMP'], true)) {
            return false;
        }
        if (count($rhs) === 1) {
            return $name === 'CURRENT_TIMESTAMP';
        }

        return ($rhs[1][1] ?? null) === '(' && $this->matching($rhs, 1) === count($rhs) - 1;
    }

    /**
     * @param array $rhs Complete SQL expression
     * @return array Expression without enclosing parentheses or explicit SQL casts
     */
    private function unwrapSql(array $rhs): array
    {
        $rhs = $this->unwrap($rhs);
        if ($this->sqlWord($rhs[0] ?? null) !== 'CAST' || ($rhs[1][1] ?? null) !== '(' || $this->matching($rhs, 1) !== count($rhs) - 1) {
            return $rhs;
        }
        $inner = array_slice($rhs, 2, -1);
        for ($cursor = 0; $cursor < count($inner); $cursor++) {
            if ($inner[$cursor][1] === '(') {
                $cursor = $this->matching($inner, $cursor);
            } elseif ($inner[$cursor][0] === 'word' && strtoupper($inner[$cursor][1]) === 'AS') {
                return $this->unwrapSql(array_slice($inner, 0, $cursor));
            }
        }

        return $rhs;
    }

    /**
     * Only local targets rebind; keys, array indices and property receivers are reads.
     * No replacement RHS value is inferred, including for nested or referenced targets.
     *
     * @param array $target Complete assignment target tokens
     * @return list<string> Locals written by bracket or list destructuring
     */
    private function destructuredVariables(array $target): array
    {
        $target = $this->withoutDocs($target);
        $opening = ($target[0][0] ?? null) === T_LIST ? 1 : 0;
        if (($target[$opening][1] ?? null) !== ($opening === 0 ? '[' : '(')
            || $this->matching($target, $opening) !== count($target) - 1) {
            return [];
        }
        $variables = [];
        foreach ($this->split(array_slice($target, $opening + 1, -1), ',') as $entry) {
            $arrow = $this->topLevel($entry, '=>');
            if ($arrow !== null) {
                $entry = array_slice($entry, $arrow + 1);
            }
            if (($entry[0][1] ?? null) === '&') {
                array_shift($entry);
            }
            if (count($entry) === 1 && $entry[0][0] === T_VARIABLE) {
                $variables[] = $entry[0][1];
            } else {
                array_push($variables, ...$this->destructuredVariables($entry));
            }
        }

        return $variables;
    }

    /**
     * A trait method gets the scope of its importing class, retained when inherited.
     * Its lexical declaration retains sinks whose proof does not depend on caller scope.
     *
     * @param ClassRecord $class Lexical declaration
     * @param MethodRecord $method Indexed method body
     * @return list<ClassRecord> Lexical declaration and proven importing scopes, without loading classes
     */
    private function methodScopes(ClassRecord $class, MethodRecord $method): array
    {
        if ($class->declarationKind !== T_TRAIT) {
            return [$class];
        }
        $scopes = [$class];
        foreach ($this->index->classes() as $candidate) {
            if (!in_array($candidate->declarationKind, [T_CLASS, T_ENUM], true)
                || $this->index->resolveMethod($candidate->name, $method->name) !== $method) {
                continue;
            }
            foreach ($candidate->traits as $trait) {
                if ($this->index->resolveMethod($trait, $method->name) === $method) {
                    $scopes[] = $candidate;
                    break;
                }
            }
        }

        return $scopes;
    }

    /**
     * @param array $lhs Assignment target tokens
     * @param ClassRecord $class Caller class, or lexical trait without a proven importing scope
     * @param array $types Local types
     * @param array $classNames Class-string bindings
     * @return array Catalog columns proven to receive this write
     */
    private function propertySinks(array $lhs, ClassRecord $class, array $types, array $classNames): array
    {
        $lhs = $this->unwrap($lhs);
        $length = count($lhs);
        if ($length >= 3 && $lhs[$length - 2][0] === T_OBJECT_OPERATOR && $lhs[$length - 1][0] === T_STRING) {
            $property = $lhs[$length - 1][1];
            $receiver = $this->receiverType(array_slice($lhs, 0, -2), $class, $types, $classNames);
            if ($receiver === null) {
                return [];
            }
            $direct = $this->entityColumns($receiver, $property);
            if ($direct !== []) {
                return $direct;
            }
            $entity = $this->index->resolveConstantClass($receiver, 'ENTITY_CLASS');
            $column = $this->magicColumn($receiver, $property, $class->name);

            return $entity === null ? [] : $this->entityColumns($entity, $column);
        }
        if ($length >= 4 && $lhs[$length - 1][1] === ']') {
            for ($cursor = 1; $cursor < $length; $cursor++) {
                if ($lhs[$cursor][1] !== '[' || $this->matching($lhs, $cursor) !== $length - 1) {
                    continue;
                }
                $receiver = $this->receiverType(array_slice($lhs, 0, $cursor), $class, $types, $classNames);
                $column = $this->stringValue(array_slice($lhs, $cursor + 1, $length - $cursor - 2), $class);

                return $receiver === null ? [] : $this->entityColumns($receiver, $column);
            }
        }

        return [];
    }

    /**
     * Only the direct match arm is proof; a camelCase name or a __get arm is not.
     *
     * @param string $receiver Object class
     * @param string $property Magic property name
     * @param string $scope Class scope of the write
     * @return ?string Direct Entity column, or null for unknown/ambiguous mapping
     */
    private function magicColumn(string $receiver, string $property, string $scope): ?string
    {
        if ($this->declaresProperty($receiver, $property, $scope)) {
            return null;
        }
        $method = $this->index->resolveMethod($receiver, '__set');
        $declaring = $method === null ? null : $this->index->find($method->class);
        if ($method === null || $declaring === null) {
            return null;
        }
        $tokens = $this->tokens('<?php ' . $method->bodySource);
        // A surrounding helper/reassignment could transform the input before the apparent map.
        if (($tokens[0][0] ?? null) !== T_MATCH) {
            return null;
        }
        // Scalar parameters are not class types in SourceIndex, so read their lexical declaration.
        $parameters = $this->setterParameters[$declaring->path][$method->line] ?? [];
        if (count($parameters) !== 2) {
            return null;
        }
        $found = [];
        for ($cursor = 0; $cursor < count($tokens); $cursor++) {
            if ($tokens[$cursor][0] !== T_MATCH || ($tokens[$cursor + 1][1] ?? null) !== '('
                || ($tokens[$cursor + 2][1] ?? null) !== $parameters[0] || ($tokens[$cursor + 3][1] ?? null) !== ')'
                || ($tokens[$cursor + 4][1] ?? null) !== '{') {
                continue;
            }
            $close = $this->matching($tokens, $cursor + 4);
            $conditions = [];
            foreach ($this->split(array_slice($tokens, $cursor + 5, $close - $cursor - 5), ',') as $arm) {
                $arrow = $this->topLevel($arm, '=>');
                if ($arrow === null) {
                    $conditions[] = $arm;
                    continue;
                }
                $conditions[] = array_slice($arm, 0, $arrow);
                $matches = array_any($conditions, fn(array $condition): bool => $this->stringValue($condition, $declaring) === $property);
                $conditions = [];
                if (!$matches) {
                    continue;
                }
                $write = $this->unwrap(array_slice($arm, $arrow + 1));
                if (count($write) >= 6 && array_column(array_slice($write, 0, 4), 1) === ['$this', '->', 'entity', '->']
                    && $write[4][0] === T_STRING && $write[5][1] === '='
                    && $this->transfersValue(array_slice($write, 6), $parameters[1])) {
                    $found[] = $write[4][1];
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * @param array $expression Setter RHS
     * @param string $value Incoming value parameter
     * @return bool Whether the arm forwards the value/cast or null, without arbitrary transformations
     */
    private function transfersValue(array $expression, string $value): bool
    {
        $expression = $this->unwrap($expression);
        if (count($expression) === 1) {
            return $expression[0][1] === $value;
        }
        $question = $this->topLevel($expression, '?');
        $colon = $this->topLevel($expression, ':');
        if ($question === null || $colon === null || $question >= $colon) {
            return false;
        }
        $left = $this->unwrap(array_slice($expression, $question + 1, $colon - $question - 1));
        $right = $this->unwrap(array_slice($expression, $colon + 1));
        $leftValue = $this->transfersValue($left, $value);
        $rightValue = $this->transfersValue($right, $value);

        return ($leftValue || $rightValue)
            && ($leftValue || (count($left) === 1 && strtolower($left[0][1]) === 'null'))
            && ($rightValue || (count($right) === 1 && strtolower($right[0][1]) === 'null'));
    }

    /**
     * @param string $receiver Object receiver class
     * @param string $property Potential magic property
     * @param string $scope Effective caller class, or a trait without a proven importing scope
     * @return bool Whether an accessible or scope-unknown real slot prevents proof of magic dispatch
     */
    private function declaresProperty(string $receiver, string $property, string $scope): bool
    {
        // A parent's own private slot remains accessible on a child that redeclares the name.
        if ($this->propertyVisibility($scope, $property) === T_PRIVATE && $this->isA($receiver, $scope)) {
            return true;
        }
        $seen = [];
        $record = $this->index->find($receiver);
        while ($record !== null && !isset($seen[strtolower($record->name)])) {
            $seen[strtolower($record->name)] = true;
            $visibility = $this->propertyVisibility($record->name, $property);
            if ($visibility !== null) {
                // A trait alone cannot prove whether its importing class can access a real slot.
                if ($this->index->find($scope)?->declarationKind === T_TRAIT) {
                    return $visibility !== T_STATIC;
                }
                return $visibility === T_PUBLIC
                    || ($visibility === T_PRIVATE && strcasecmp($scope, $record->name) === 0)
                    || ($visibility === T_PROTECTED && ($this->isA($scope, $record->name) || $this->isA($record->name, $scope)));
            }
            $record = $record->parent === null ? null : $this->index->find($record->parent);
        }

        return false;
    }

    /**
     * Trait properties belong to the importing class; parents keep their own declaring scope.
     *
     * @param string $class Class or trait whose own slots are read
     * @param string $property Real property name
     * @param list<string> $seen Traversed traits
     * @return ?int Visibility token, T_STATIC for a static slot, or null for no declaration
     */
    private function propertyVisibility(string $class, string $property, array $seen = []): ?int
    {
        $record = $this->index->find($class);
        if ($record === null || in_array(strtolower($class), $seen, true)) {
            return null;
        }
        $visibility = $this->declaredProperties[$record->path][$record->line][$property] ?? null;
        if ($visibility !== null) {
            return $visibility;
        }
        $seen[] = strtolower($class);
        foreach ($record->traits as $trait) {
            $visibility = $this->propertyVisibility($trait, $property, $seen);
            if ($visibility !== null) {
                return $visibility;
            }
        }

        return null;
    }

    /**
     * @param string $class Candidate class
     * @param string $ancestor Required class or ancestor
     * @return bool Whether the index proves the same class or a parent link
     */
    private function isA(string $class, string $ancestor): bool
    {
        $seen = [];
        $record = $this->index->find($class);
        while ($record !== null && !isset($seen[strtolower($record->name)])) {
            if (strcasecmp($record->name, $ancestor) === 0) {
                return true;
            }
            $seen[strtolower($record->name)] = true;
            $record = $record->parent === null ? null : $this->index->find($record->parent);
        }

        return false;
    }

    /**
     * @param string $entity Proven Entity receiver class
     * @param ?string $column Proven column name
     * @return array Matching mapped standard moments; declaration/table identities stay separate
     */
    private function entityColumns(string $entity, ?string $column): array
    {
        if ($column === null) {
            return [];
        }

        return array_values(array_filter($this->catalog, fn(array $entry): bool => $entry['entity'] !== null
            && strcasecmp($entry['entity'], $entity) === 0 && $entry['column'] === $column && $entry['mapped']
            && ($entry['projectStorage'] === null || $entry['frameworkOwned'])));
    }

    /**
     * @param array $expression Receiver expression, class names already qualified by SourceIndex
     * @param ClassRecord $class Lexical class
     * @param array $types Local receiver types
     * @param array $classNames Local class-string bindings
     * @return ?string Proven class, null when inference would be needed
     */
    private function receiverType(array $expression, ClassRecord $class, array $types, array $classNames): ?string
    {
        $expression = $this->unwrap($expression);
        $length = count($expression);
        if ($length === 1 && $expression[0][0] === T_VARIABLE) {
            return $expression[0][1] === '$this' ? $class->name : ($types[$expression[0][1]] ?? null);
        }
        if ($length >= 2 && $expression[0][0] === T_NEW && in_array($expression[1][0], self::NAMES, true)
            && ($length === 2 || (($expression[2][1] ?? null) === '(' && $this->matching($expression, 2) === $length - 1))) {
            return $this->className($expression[1][1], $class);
        }
        if ($length >= 3 && $expression[$length - 2][0] === T_OBJECT_OPERATOR && $expression[$length - 1][0] === T_STRING) {
            $receiver = $this->receiverType(array_slice($expression, 0, -2), $class, $types, $classNames);
            if ($receiver === null) {
                return null;
            }
            if ($expression[$length - 1][1] === 'entity') {
                return $this->index->resolveConstantClass($receiver, 'ENTITY_CLASS') ?? $this->propertyType($receiver, 'entity');
            }

            return $this->propertyType($receiver, $expression[$length - 1][1]);
        }
        if ($length >= 5 && $expression[$length - 4][0] === T_DOUBLE_COLON
            && strcasecmp($expression[$length - 3][1], 'create') === 0
            && array_column(array_slice($expression, -2), 1) === ['(', ')']) {
            $receiver = $this->classValue(array_slice($expression, 0, -4), $class, $types, $classNames);

            return $receiver !== null && $this->index->resolveConstantClass($receiver, 'ENTITY_CLASS') !== null ? $receiver : null;
        }

        return null;
    }

    /**
     * @param array $expression Class name, class-string local, or constant expression
     * @param ClassRecord $class Lexical class
     * @param array $types Receiver types
     * @param array $classNames Class-string bindings
     * @return ?string Resolved class constant value without running a factory
     */
    private function classValue(array $expression, ClassRecord $class, array $types, array $classNames): ?string
    {
        $expression = $this->unwrap($expression);
        if (count($expression) === 1) {
            return $expression[0][0] === T_VARIABLE ? ($classNames[$expression[0][1]] ?? null)
                : (in_array($expression[0][0], self::NAMES, true) ? $this->className($expression[0][1], $class) : null);
        }
        $length = count($expression);
        if ($length < 3 || $expression[$length - 2][0] !== T_DOUBLE_COLON || $expression[$length - 1][0] !== T_STRING) {
            return null;
        }
        $base = array_slice($expression, 0, -2);
        $receiver = $this->receiverType($base, $class, $types, $classNames)
            ?? $this->classValue($base, $class, $types, $classNames);

        return $receiver === null ? null : $this->index->resolveConstantClass($receiver, $expression[$length - 1][1]);
    }

    /**
     * @param string $receiver Class whose typed property is read
     * @param string $property Property name
     * @param list<string> $seen Cycle guard
     * @return ?string Declared property type or the existing objectCollection constant seam
     */
    private function propertyType(string $receiver, string $property, array $seen = []): ?string
    {
        $record = $this->index->find($receiver);
        if ($record === null || in_array(strtolower($receiver), $seen, true)) {
            return null;
        }
        if (isset($record->propertyTypes[$property])) {
            return $record->propertyTypes[$property];
        }
        if ($property === 'objectCollection') {
            $collection = $this->index->resolveConstantClass($receiver, 'OBJECT_COLLECTION_CLASS');
            if ($collection !== null) {
                return $collection;
            }
        }
        $seen[] = strtolower($receiver);
        foreach ([...$record->traits, ...($record->parent === null ? [] : [$record->parent])] as $parent) {
            $type = $this->propertyType($parent, $property, $seen);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param array $rhs RHS tokens; arithmetic and general integer flow are deliberately not interpreted
     * @param ClassRecord $class Lexical class
     * @param array $types Proven receiver types
     * @param array $context Namespace/function import context
     * @param array $functions Free-function declarations
     * @return bool Whether the complete expression proves seconds or SQL datetime
     */
    private function badRhs(array $rhs, ClassRecord $class, array $types, array $context, array $functions): bool
    {
        $rhs = $this->unwrap($rhs);
        $literal = $this->literalString($rhs);
        if ($literal !== null) {
            return preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?\z/', $literal) === 1;
        }
        $length = count($rhs);
        if ($length < 3 || $rhs[$length - 1][1] !== ')') {
            return false;
        }
        if (in_array($rhs[0][0], self::NAMES, true) && $rhs[1][1] === '(' && $this->matching($rhs, 1) === $length - 1) {
            $function = $this->builtinFunction($rhs[0][1], $class, $context, $functions);
            if ($function === 'time') {
                return $length === 3;
            }
            if ($function === 'date' || $function === 'gmdate') {
                $arguments = $this->split(array_slice($rhs, 2, -1), ',');
                $format = null;
                foreach ($arguments as $position => $argument) {
                    if (($argument[1][1] ?? null) === ':') {
                        if ($argument[0][1] !== 'format') {
                            continue;
                        }
                        $argument = array_slice($argument, 2);
                    } elseif ($position !== 0) {
                        continue;
                    }
                    $format = $this->literalString($argument);
                    break;
                }

                return in_array($format, ['Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s.v'], true);
            }
        }
        if ($length === 5 && $rhs[1][0] === T_DOUBLE_COLON && strcasecmp($rhs[2][1], 'getSqlDateTime') === 0
            && $rhs[3][1] === '(' && strcasecmp($this->className($rhs[0][1], $class), self::TIME_HELPER) === 0) {
            return true;
        }
        if ($length >= 5 && in_array($rhs[$length - 4][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && strcasecmp($rhs[$length - 3][1], 'getTimestamp') === 0
            && array_column(array_slice($rhs, -2), 1) === ['(', ')']) {
            $receiver = $this->receiverType(array_slice($rhs, 0, -4), $class, $types, []);

            return $receiver !== null && $this->isDateTime($receiver);
        }

        return false;
    }

    /**
     * @param string $class Potential DateTimeInterface implementation
     * @param list<string> $seen Traversed declarations
     * @return bool Whether the receiver has the native timestamp contract
     */
    private function isDateTime(string $class, array $seen = []): bool
    {
        $key = strtolower(ltrim($class, '\\'));
        if (in_array($key, ['datetime', 'datetimeimmutable', 'datetimeinterface'], true)) {
            return true;
        }
        $record = $this->index->find($class);
        if ($record === null || in_array($key, $seen, true)) {
            return false;
        }
        $seen[] = $key;
        foreach ([...$record->interfaces, ...($record->parent === null ? [] : [$record->parent])] as $parent) {
            if ($this->isDateTime($parent, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name Written function name
     * @param ClassRecord $class Calling class
     * @param array $context Function imports
     * @param array $functions All indexed free-function declarations
     * @return ?string Global builtin name, null for user functions and unknown names
     */
    private function builtinFunction(string $name, ClassRecord $class, array $context, array $functions): ?string
    {
        $name = strtolower($name);
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $imported = $context['functionImports'][$name] ?? null;
        if ($imported !== null) {
            return strtolower($imported);
        }
        if (str_contains($name, '\\')) {
            return null;
        }
        $namespace = substr($class->name, 0, (int)strrpos($class->name, '\\'));

        return isset($functions[strtolower($namespace . '\\' . $name)]) || isset($functions[$name]) ? null : $name;
    }

    /**
     * This supplements the shared index only where it has no lexical import record.
     * Imports and function declarations come from PHP tokens, never quoted code or comments.
     *
     * @param string $source Source file
     * @return array{declarations: array<int, array>, setters: array<int, list<string>>,
     *     functions: array<string, true>, properties: array<int, array<string, int>>}
     *     Import context at each declaration, setter selectors and namespace function shadows
     */
    private function fileContext(string $source): array
    {
        $tokens = $this->tokens($source, false, TOKEN_PARSE);
        $namespace = '';
        $classes = [];
        $functionImports = [];
        $functions = [];
        $declarations = [];
        $setters = [];
        $properties = [];
        $classLine = null;
        $classEnd = null;
        $depth = 0;
        $parentheses = 0;
        $namespaceDepth = 0;
        $visibility = T_PUBLIC;
        $static = false;
        for ($cursor = 0; $cursor < count($tokens); $cursor++) {
            if ($classEnd !== null && $cursor > $classEnd) {
                $classLine = null;
                $classEnd = null;
            }
            if ($tokens[$cursor][0] === T_ATTRIBUTE) {
                $cursor = $this->matching($tokens, $cursor);
                continue;
            }
            if ($tokens[$cursor][0] === T_NAMESPACE) {
                $named = ($tokens[$cursor + 1][1] ?? null) !== '{';
                $namespace = '';
                if ($named) {
                    $namespace = trim($tokens[$cursor + 1][1], '\\');
                }
                $namespaceDepth = ($tokens[$cursor + ($named ? 2 : 1)][1] ?? null) === '{' ? 1 : 0;
                $classes = [];
                $functionImports = [];
            }
            if (in_array($tokens[$cursor][0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $depth === $namespaceDepth
                && ($tokens[$cursor + 1][0] ?? null) === T_STRING) {
                $classLine = $tokens[$cursor][2];
                $declarations[$classLine] = compact('classes', 'functionImports');
                $opening = $cursor + 2;
                while (isset($tokens[$opening]) && $tokens[$opening][1] !== '{') {
                    $opening++;
                }
                $classEnd = $this->matching($tokens, $opening);
            }
            if ($depth === $namespaceDepth + 1 && $parentheses === 0) {
                if (in_array($tokens[$cursor][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
                    $visibility = $tokens[$cursor][0] === T_VAR ? T_PUBLIC : $tokens[$cursor][0];
                } elseif ($tokens[$cursor][0] === T_STATIC) {
                    $static = true;
                } elseif ($tokens[$cursor][0] === T_FUNCTION) {
                    $visibility = T_PUBLIC;
                    $static = false;
                }
            }
            if ($tokens[$cursor][0] === T_VARIABLE && $depth === $namespaceDepth + 1 && $parentheses === 0 && $classLine !== null) {
                $properties[$classLine][ltrim($tokens[$cursor][1], '$')] = $static ? T_STATIC : $visibility;
            }
            if ($tokens[$cursor][0] === T_FUNCTION && ($tokens[$cursor + 1][0] ?? null) === T_STRING
                && strcasecmp($tokens[$cursor + 1][1], '__construct') === 0
                && $depth === $namespaceDepth + 1 && $classLine !== null) {
                $close = $this->matching($tokens, $cursor + 2);
                foreach ($this->split(array_slice($tokens, $cursor + 3, $close - $cursor - 3), ',') as $parameter) {
                    $promoted = null;
                    for ($position = 0; $position < count($parameter); $position++) {
                        $part = $parameter[$position];
                        if ($part[0] === T_ATTRIBUTE) {
                            $position = $this->matching($parameter, $position);
                            continue;
                        }
                        if (in_array($part[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                            $promoted = $part[0];
                        } elseif ($part[0] === T_VARIABLE) {
                            if ($promoted !== null) {
                                $properties[$classLine][ltrim($part[1], '$')] = $promoted;
                            }
                            break;
                        }
                    }
                }
            }
            if ($tokens[$cursor][0] === T_FUNCTION && ($tokens[$cursor + 1][0] ?? null) === T_STRING
                && strcasecmp($tokens[$cursor + 1][1], '__set') === 0) {
                for ($parameter = $cursor + 3; isset($tokens[$parameter]) && $tokens[$parameter][1] !== ')'; $parameter++) {
                    if ($tokens[$parameter][0] === T_VARIABLE) {
                        $setters[$tokens[$cursor + 1][2]][] = $tokens[$parameter][1];
                    }
                }
            }
            if ($tokens[$cursor][0] === T_USE && $depth === $namespaceDepth) {
                $end = $cursor + 1;
                while (isset($tokens[$end]) && $tokens[$end][1] !== ';') {
                    $end++;
                }
                $imports = array_slice($tokens, $cursor + 1, $end - $cursor - 1);
                $kind = ($imports[0][0] ?? null) === T_FUNCTION ? T_FUNCTION : T_CLASS;
                if (in_array($imports[0][0] ?? null, [T_FUNCTION, T_CONST], true)) {
                    if ($imports[0][0] === T_CONST) {
                        $cursor = $end;
                        continue;
                    }
                    array_shift($imports);
                }
                $group = $this->topLevel($imports, '{');
                $prefix = '';
                if ($group !== null) {
                    $prefix = implode('', array_column(array_slice($imports, 0, $group), 1));
                    $imports = array_slice($imports, $group + 1, -1);
                }
                foreach ($this->split($imports, ',') as $import) {
                    if ($import === []) {
                        continue;
                    }
                    $entryKind = $kind;
                    if (in_array($import[0][0], [T_FUNCTION, T_CONST], true)) {
                        $entryKind = array_shift($import)[0];
                    }
                    $as = $this->topLevel($import, 'as');
                    $name = ltrim($prefix . implode('', array_column($as === null ? $import : array_slice($import, 0, $as), 1)), '\\');
                    $short = $as === null ? substr($name, (int)strrpos('\\' . $name, '\\')) : $import[$as + 1][1];
                    if ($entryKind === T_FUNCTION) {
                        $functionImports[strtolower($short)] = $name;
                    } elseif ($entryKind === T_CLASS) {
                        $classes[strtolower($short)] = $name;
                    }
                }
                $cursor = $end;
                continue;
            }
            if ($tokens[$cursor][0] === T_FUNCTION && $depth === $namespaceDepth) {
                $name = $cursor + 1;
                if (($tokens[$name][1] ?? null) === '&') {
                    $name++;
                }
                if (($tokens[$name][0] ?? null) === T_STRING) {
                    $functions[strtolower(ltrim($namespace . '\\' . $tokens[$name][1], '\\'))] = true;
                }
            }
            if ($tokens[$cursor][1] === '{') {
                $depth++;
            } elseif ($tokens[$cursor][1] === '}') {
                $depth--;
                if ($depth <= $namespaceDepth + 1) {
                    $visibility = T_PUBLIC;
                    $static = false;
                }
            } elseif ($tokens[$cursor][1] === '(') {
                $parentheses++;
            } elseif ($tokens[$cursor][1] === ')') {
                $parentheses--;
            } elseif ($tokens[$cursor][1] === ';' && $depth === $namespaceDepth + 1 && $parentheses === 0) {
                $visibility = T_PUBLIC;
                $static = false;
            }
        }

        return compact('declarations', 'setters', 'functions', 'properties');
    }

    /**
     * @param string $name Class type written in a local @var
     * @param ClassRecord $class Lexical class
     * @param array<string, string> $imports Class imports
     * @return string Resolved type name, without loading it
     */
    private function resolveName(string $name, ClassRecord $class, array $imports): string
    {
        if (str_starts_with($name, '\\') || in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return $this->className($name, $class);
        }
        $parts = explode('\\', $name, 2);
        if (isset($imports[strtolower($parts[0])])) {
            return implode('\\', [$imports[strtolower($parts[0])], ...array_slice($parts, 1)]);
        }
        $namespace = substr($class->name, 0, (int)strrpos($class->name, '\\'));

        return ltrim($namespace . '\\' . preg_replace('/^namespace\\\\/i', '', $name), '\\');
    }

    /**
     * @param string $name Already qualified name or lexical self/static/parent
     * @param ClassRecord $class Lexical class
     * @return string Absolute name without leading backslash
     */
    private function className(string $name, ClassRecord $class): string
    {
        return match (strtolower($name)) {
            'self', 'static' => $class->name,
            'parent' => $class->parent ?? $class->name,
            default => ltrim($name, '\\'),
        };
    }

    /**
     * @param array $expression Literal string or scalar class constant
     * @param ClassRecord $class Lexical class
     * @return ?string Literal value; dynamic columns are deliberately unknown
     */
    private function stringValue(array $expression, ClassRecord $class): ?string
    {
        $expression = $this->unwrap($expression);
        if (count($expression) === 3 && $expression[1][0] === T_DOUBLE_COLON && $expression[2][0] === T_STRING) {
            $raw = $this->index->resolveConstant($this->className($expression[0][1], $class), $expression[2][1]);

            return $raw === null ? null : $this->literalString($this->tokens('<?php ' . $raw));
        }

        return $this->literalString($expression);
    }

    /**
     * @param array $expression String literal or literal-only concatenation
     * @return ?string Decoded PHP string, null for interpolation or expressions
     */
    private function literalString(array $expression): ?string
    {
        $parts = $this->split($this->unwrap($expression), '.');
        $value = '';
        foreach ($parts as $part) {
            $part = $this->unwrap($part);
            if (count($part) !== 1 || $part[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $text = $part[0][1];
            $inner = substr($text, 1, -1);
            $value .= $text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
        }

        return $value;
    }

    /**
     * @param string $source PHP source, including opening tag
     * @param bool $keepDocs Whether local @var tags are needed
     * @param int $flags TOKEN_PARSE for complete files; lexical bodies/constants are fragments
     * @return array Significant [token kind, text, line] tuples
     */
    private function tokens(string $source, bool $keepDocs = false, int $flags = 0): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source, $flags) as $token) {
            if (is_array($token)) {
                [$kind, $text, $line] = $token;
                if (!in_array($kind, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true) && ($keepDocs || $kind !== T_DOC_COMMENT)) {
                    if (in_array($kind, self::CASTS, true)) {
                        $text = preg_replace('/\s+/', '', $text);
                    }
                    $tokens[] = [$kind, $text, $line];
                }
                $line += substr_count($text, "\n");
            } else {
                $tokens[] = [$token, $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * @param array $tokens Significant tokens
     * @return array Same tokens without docblocks
     */
    private function withoutDocs(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn(array $token): bool => $token[0] !== T_DOC_COMMENT));
    }

    /**
     * Keep lexical positions and annotation tokens while reading adjacent code.
     *
     * @param array $tokens Method tokens with retained docblocks
     * @param int $cursor First candidate position
     * @param int $step Forward (1) or backward (-1) traversal
     * @return int Nearest code position, possibly outside the token sequence
     */
    private function codeTokenPosition(array $tokens, int $cursor, int $step = 1): int
    {
        while (($tokens[$cursor][0] ?? null) === T_DOC_COMMENT) {
            $cursor += $step;
        }

        return $cursor;
    }

    /**
     * @param array $tokens Expression tokens
     * @return array Expression without enclosing parentheses and explicit casts
     */
    private function unwrap(array $tokens): array
    {
        $tokens = $this->withoutDocs($tokens);
        while ($tokens !== []) {
            if (in_array($tokens[0][0], self::CASTS, true)) {
                array_shift($tokens);
            } elseif ($tokens[0][1] === '(' && $this->matching($tokens, 0) === count($tokens) - 1) {
                $tokens = array_slice($tokens, 1, -1);
            } else {
                break;
            }
        }

        return $tokens;
    }

    /**
     * @param array $tokens Token sequence
     * @param int $start Opening delimiter position
     * @return int Matching closing position; end-of-input for an incomplete expression
     */
    private function matching(array $tokens, int $start): int
    {
        $stack = [];
        for ($cursor = $start; $cursor < count($tokens); $cursor++) {
            $text = $tokens[$cursor][1];
            // PHP's `#[` is one opener; its closing `]` is an ordinary token.
            if ($tokens[$cursor][0] === T_ATTRIBUTE || in_array($text, ['(', '[', '{'], true)) {
                $stack[] = $text;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                array_pop($stack);
                if ($stack === []) {
                    return $cursor;
                }
            }
        }

        return count($tokens);
    }

    /**
     * @param array $tokens Token sequence
     * @param string $separator Top-level delimiter
     * @return ?int First top-level delimiter position
     */
    private function topLevel(array $tokens, string $separator): ?int
    {
        for ($cursor = 0; $cursor < count($tokens); $cursor++) {
            if ($tokens[$cursor][1] === $separator) {
                return $cursor;
            }
            if ($tokens[$cursor][0] === T_ATTRIBUTE || in_array($tokens[$cursor][1], ['(', '[', '{'], true)) {
                $cursor = $this->matching($tokens, $cursor);
            }
        }

        return null;
    }

    /**
     * @param array $tokens Token sequence
     * @param string $separator Top-level delimiter
     * @return list<array> Complete top-level pieces
     */
    private function split(array $tokens, string $separator): array
    {
        $parts = [];
        while (($position = $this->topLevel($tokens, $separator)) !== null) {
            $parts[] = array_slice($tokens, 0, $position);
            $tokens = array_slice($tokens, $position + 1);
        }
        $parts[] = $tokens;

        return $parts;
    }

    /**
     * @param array $tokens Method tokens
     * @param int $start First RHS token
     * @return int First top-level comma, semicolon, closing delimiter or enclosing ternary colon
     */
    private function expressionEnd(array $tokens, int $start): int
    {
        $ternaries = 0;
        for ($cursor = $start; $cursor < count($tokens); $cursor++) {
            if (in_array($tokens[$cursor][1], [';', ',', ')', ']', '}'], true)) {
                return $cursor;
            }
            if ($tokens[$cursor][0] === T_ATTRIBUTE || in_array($tokens[$cursor][1], ['(', '[', '{'], true)) {
                $cursor = $this->matching($tokens, $cursor);
            } elseif ($tokens[$cursor][1] === '?') {
                $ternaries++;
            } elseif ($tokens[$cursor][1] === ':') {
                if ($ternaries === 0) {
                    return $cursor;
                }
                $ternaries--;
            }
        }

        return count($tokens);
    }

    /**
     * PHP argument names include keyword tokens. Require an identifier lexeme at the
     * start of a call argument, never a ternary arm, switch label or array entry.
     *
     * @param array $tokens Method tokens
     * @param int $cursor Possible argument colon
     * @return bool Whether the colon separates an argument name from its value
     */
    private function namedArgumentDelimiter(array $tokens, int $cursor): bool
    {
        if (($tokens[$cursor][1] ?? null) !== ':') {
            return false;
        }
        $name = $this->codeTokenPosition($tokens, $cursor - 1, -1);
        $separator = $this->codeTokenPosition($tokens, $name - 1, -1);
        if (!in_array($tokens[$separator][1] ?? null, ['(', ','], true)
            || preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $tokens[$name][1]) !== 1) {
            return false;
        }
        $depth = 0;
        for ($opening = $separator; $opening >= 0; $opening--) {
            $text = $tokens[$opening][1];
            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif ($tokens[$opening][0] === T_ATTRIBUTE || in_array($text, ['(', '[', '{'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
        }
        if (($tokens[$opening][1] ?? null) !== '(') {
            return false;
        }
        $callable = $this->codeTokenPosition($tokens, $opening - 1, -1);

        return in_array($tokens[$callable][0] ?? null, [...self::NAMES, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_EXIT], true)
            || in_array($tokens[$callable][1] ?? null, [')', ']', '}', '"'], true);
    }

    /**
     * At the target's nesting level, ternary arms, named arguments and switch labels
     * all separate the target from its preceding expression. Nested receiver/index
     * expressions remain whole for the independent receiver proof.
     *
     * @param array $tokens Method tokens
     * @param int $equal Assignment operator position
     * @return int First target token after the preceding statement or expression delimiter
     */
    private function assignmentStart(array $tokens, int $equal): int
    {
        $depth = 0;
        for ($cursor = $equal - 1; $cursor >= 0; $cursor--) {
            $text = $tokens[$cursor][1];
            if (in_array($text, [')', ']'], true)) {
                $depth++;
            } elseif ($tokens[$cursor][0] === T_ATTRIBUTE || in_array($text, ['(', '['], true)) {
                if ($depth === 0) {
                    return $this->codeTokenPosition($tokens, $cursor + 1);
                }
                $depth--;
            }
            if ($depth === 0 && (in_array($text, [';', '{', '}', ',', '=>', '=', '?', ':'], true)
                || in_array($tokens[$cursor][0], [T_RETURN, T_YIELD], true))) {
                return $this->codeTokenPosition($tokens, $cursor + 1);
            }
        }

        return $this->codeTokenPosition($tokens, 0);
    }

    /**
     * @param array $tokens Method tokens
     * @param int $cursor Variable position
     * @return bool Whether this is a standalone assignment, not an expression inside a call
     */
    private function statementStart(array $tokens, int $cursor): bool
    {
        $previous = $this->codeTokenPosition($tokens, $cursor - 1, -1);

        return $previous < 0 || in_array($tokens[$previous][1], [';', '{', '}'], true);
    }

    /**
     * @param array $tuple Structural subject, finding or token tuple
     * @return string Canonical JSON preserving Unicode, slashes and numeric-string names
     */
    private function json(array $tuple): string
    {
        return json_encode($tuple, self::JSON_FLAGS);
    }
}
