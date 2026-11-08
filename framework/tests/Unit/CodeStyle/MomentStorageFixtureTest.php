<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Testing\MomentStorageBaseline;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSourceRule;
use Hilos\Tests\CodeStyle\MomentStorage\MomentStorageSources;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exact PHP/SQL sink reports, with semantic metadata injected instead of booting an application. */
final class MomentStorageFixtureTest extends TestCase
{
    private const string ROOT = 'CodeStyle/Fixtures/MomentStorage';
    private const string ENTITY = 'MomentFixtures\EventEntity';
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @return iterable<string, array{string, list<array>, string, string}> Proven PHP writer matrix */
    public static function writers(): iterable
    {
        yield 'first native writes after case and default' => ['switchLabels', [
            ['at', '$entity->at = time()', 122],
            ['created_at', '$entity->created_at = time()', 125],
        ], 'Bad', 'Bad.php'];
        yield 'native writes after an ordinary switch statement' => ['switchAfterStatement', [
            ['at', '$entity->at = time()', 135],
            ['created_at', '$entity->created_at = time()', 139],
        ], 'Bad', 'Bad.php'];
        yield 'case expressions with ternary named arguments and match' => ['switchExpressions', [
            ['at', '($entity)->at = time()', 148],
            ['created_at', '$entity[\'created_at\'] = time()', 151],
            ['at', '$entity->at = time()', 154],
        ], 'Bad', 'Bad.php'];
        yield 'named date formats including reordered imported and global builtins' => ['namedDates', [
            ['at', '$entity->at = date(format: \'Y-m-d H:i:s\')', 161],
            ['created_at', '$entity->created_at = gmdate(format: \'Y-m-d H:i:s\')', 162],
            ['at', '$entity->at = utcDate(timestamp: 0, format: \'Y-m-d \' . \'H:i:s.u\')', 163],
            ['created_at', '$entity->created_at = \\date(timestamp: 0, format: (\'Y-m-d H:i:s.v\'))', 164],
        ], 'Bad', 'Bad.php'];
        yield 'named date alias retains initializer and occurrence multiplicity' => ['namedDateAlias', [
            ['at', '$entity->at = $when', 170, '$when = utcDate(timestamp: 0, format: \'Y-m-d H:i:s\')'],
            ['at', '$entity->at = $when', 171, '$when = utcDate(timestamp: 0, format: \'Y-m-d H:i:s\')'],
        ], 'Bad', 'Bad.php'];
        yield 'native Entity' => ['native', [['at', '$entity->at = time()', 23]], 'Bad', 'Bad.php'];
        yield 'resolved parameter doc' => ['documented', [['at', '$entity->at = time()', 29]], 'Bad', 'Bad.php'];
        yield 'resolved local var' => ['localDoc', [['at', '$entity->at = time()', 35]], 'Bad', 'Bad.php'];
        yield 'new Entity' => ['constructed', [
            ['at', '$entity->at = time()', 41],
            ['at', 'MomentFixtures\EventEntity::fromRow([\'at\' => \time()])', 42],
        ], 'Bad', 'Bad.php'];
        yield 'cast multiline builtin alias' => ['castAlias', [
            ['created_at', '$entity->created_at = (int)(seconds())', 47],
        ], 'Bad', 'Bad.php'];
        yield 'DateTime contract' => ['timestamp', [
            ['at', '$entity->at = $date->getTimestamp()', 54],
            ['created_at', '$entity->created_at = (new DateTimeImmutable())->getTimestamp()', 55],
        ], 'Bad', 'Bad.php'];
        yield 'SQL datetime PHP values' => ['dates', [
            ['at', '$entity->at = date(\'Y-m-d H:i:s\')', 60],
            ['at', '$entity->at = utcDate(\'Y-m-d \' . \'H:i:s\')', 61],
            ['created_at', '$entity->created_at = \'2026-11-07 \' . \'12:13:14.123\'', 62],
        ], 'Bad', 'Bad.php'];
        yield 'qualified TimeHelper' => ['helper', [
            ['at', '$entity->at = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()', 67],
            ['created_at', '$entity->created_at = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()', 68],
        ], 'Bad', 'Bad.php'];
        yield 'Entity column indexes and fromRow entries' => ['arrays', [
            ['at', '$entity[MomentFixtures\EventEntity::at] = time()', 73],
            ['created_at', '$entity[\'created_at\'] = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()', 74],
            ['at', 'MomentFixtures\EventEntity::fromRow([\'at\' => time()])', 75],
            ['created_at', 'MomentFixtures\EventEntity::fromRow([MomentFixtures\EventEntity::created_at => \'2026-11-07 12:13:14\'])', 75],
        ], 'Bad', 'Bad.php'];
        yield 'Object magic receivers' => ['magic', [
            ['at', '$object->happenedAt = time()', 80],
            ['at', '$this->object->happenedAt = time()', 81],
            ['at', '$created->happenedAt = time()', 83],
            ['at', '$created->happenedAt = time()', 84],
        ], 'Bad', 'Bad.php'];
        yield 'inherited Object mapping' => ['inherited', [['at', '$object->happenedAt = time()', 89]], 'Bad', 'Bad.php'];
        yield 'OBJECT_CLASS factory chain' => ['factory', [['at', '$object->happenedAt = time()', 96]], 'Bad', 'Bad.php'];
        yield 'typed Entity property' => ['directProperty', [['at', '$this->entity->at = time()', 101]], 'Bad', 'Bad.php'];
        yield 'Object entity receiver' => ['directEntity', [
            ['created_at', '$this->entity->created_at = time()', 36],
        ], 'EventObject', 'Models.php'];
        yield 'one RHS alias and repeated occurrences' => ['alias', [
            ['at', '$entity->at = (string)($now)', 107, '$now = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()'],
            ['at', '$entity->at = (string)($now)', 108, '$now = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()'],
        ], 'Bad', 'Bad.php'];
        yield 'significant token multiplicity' => ['duplicate', [
            ['at', '$entity->at = time()', 113],
            ['at', '$entity->at = time()', 115],
        ], 'Bad', 'Bad.php'];
        yield 'explicit global builtin past namespace shadow' => ['global', [
            ['at', '$entity->at = \time()', 31],
        ], 'MomentShadow\Shadow', 'Shadow.php'];
        yield 'renamed setter parameters and nullable forwarding' => ['nullable', [
            ['at', '$object->when = TIME()', 48],
        ], 'Edges', 'Edges.php'];
        yield 'local DateTime and case-insensitive method' => ['newTimestamp', [
            ['at', '$entity->at = $date->GETTIMESTAMP()', 54],
        ], 'Edges', 'Edges.php'];
        yield 'grouped imports and double quoted format' => ['groupedImports', [
            ['at', '$local->at = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()', 60],
            ['at', '$entity->at = sqlDate("Y-m-d H:i:s")', 61],
        ], 'Edges', 'Edges.php'];
        yield 'multi-condition and parenthesized match arm' => ['multi', [
            ['at', '$this->when = time()', 113],
            ['at', '$this->other = time()', 114],
        ], 'ExtraEdges', 'Edges.php'];
        yield 'nullable local doc on unknown initializer' => ['localDocAssignment', [
            ['at', '$entity->at = time()', 121],
        ], 'ExtraEdges', 'Edges.php'];
        yield 'local doc without variable name' => ['shortDocAssignment', [
            ['at', '$entity->at = time()', 128],
        ], 'ExtraEdges', 'Edges.php'];
        yield 'namespace-local function import context' => ['write', [
            ['at', '$entity->at = clock()', 14],
        ], 'MomentNamespaceFirst\Writer', 'Namespaces.php'];
    }

    /** @return iterable<string, array{string, list<array>}> Exact significant SQL tokens and their PHP execution expressions */
    public static function sqlWriters(): iterable
    {
        yield 'case-insensitive SQL columns retain canonical subjects' => ['columnCase', [[
            ['UPDATE', 'event', 'SET', '`AT`', '=', 'NOW', '(', ')', ',', '`CrEaTeD_At`', '=', 'CURRENT_TIMESTAMP'],
            'Hilos\Database\Database::sql(SQL)', 114, [['event', 'at'], ['event', 'created_at']],
        ], [
            ['INSERT', 'INTO', 'event', '(', '`AT`', ',', '`cReAtEd_At`', ')', 'VALUES', '(', '?', ',', '?', ')'],
            'Hilos\Database\Database::sql(SQL, [time(), time()])', 115, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'named date formats in inline SQL parameters' => ['namedDates', [[
            ['UPDATE', 'event', 'SET', 'at', '=', '?', ',', 'created_at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [date(format: \'Y-m-d H:i:s\'), gmdate(timestamp: 0, format: \'Y-m-d H:i:s\')])',
            120, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'named date format in one local SQL RHS alias' => ['namedDateAlias', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', '?', ')'],
            'Hilos\Database\Database::sql(SQL, [$when])', 126, [['event', 'at']],
            '$when = date(timestamp: 0, format: \'Y-m-d H:i:s\')',
        ]]];
        yield 'literal INSERT' => ['insert', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ',', 'created_at', ')', 'VALUES', '(', 'NOW', '(', ')', ',',
                "'2026-11-07 12:13:14.123'", ')'],
            'Hilos\Database\Database::sql(SQL)', 20, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'literal UPDATE' => ['update', [[
            ['UPDATE', 'event', 'SET', 'at', '=', 'CURRENT_TIMESTAMP', ',', 'created_at', '=', 'UNIX_TIMESTAMP', '(', 'NOW', '(', ')', ')',
                'WHERE', 'id', '=', '1'],
            'Hilos\Database\Database::sql(SQL)', 25, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'positional time and DateTime parameters' => ['positional', [[
            ['INSERT', 'INTO', 'event', '(', 'id', ',', 'at', ',', 'created_at', ')', 'VALUES', '(', '?', ',', '?', ',', '?', ')'],
            'Hilos\Database\Database::sql(SQL, [17, time(), $date->getTimestamp()])', 30, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'named helper and concatenated PHP datetime' => ['named', [[
            ['UPDATE', 'event', 'SET', 'at', '=', ':at', ',', 'created_at', '=', ':created', 'WHERE', 'id', '=', ':id'],
            'Hilos\Database\Database::sql(SQL, [\'id\' => 7, \'created\' => Hilos\Utils\Helpers\TimeHelper::getSqlDateTime(), '
                . '\'at\' => \'2026-11-07 \' . \'12:13:14\',])', 35, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'one local PHP RHS alias' => ['alias', [[
            ['UPDATE', 'event', 'SET', 'at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [(string)($when)])', 43, [['event', 'at']],
            '$when = Hilos\Utils\Helpers\TimeHelper::getSqlDateTime()',
        ]]];
        yield 'constants backticks casts and SQL case' => ['constants', [[
            ['UPDATE', '`event`', 'SET', '`at`', '=', '(', 'cAsT', '(', '(', 'nOw', '(', '3', ')', ')', 'As', 'SIGNED', ')', ')'],
            'Hilos\Database\Database::sql(SQL)', 48, [['event', 'at']],
        ]]];
        yield 'DB-only Entity column' => ['dbOnly', [[
            ['UPDATE', 'event', 'SET', 'unmapped', '=', 'CURRENT_TIMESTAMP', '(', '3', ')'],
            'Hilos\Database\Database::sql(SQL)', 53, [['event', 'unmapped']],
        ]]];
        yield 'non-Entity provider column' => ['provider', [[
            ['INSERT', 'INTO', 'bootstrap', '(', 'claimed_at', ')', 'VALUES', '(', 'CURRENT_TIMESTAMP', '(', '3', ')', ')'],
            'Hilos\Database\Database::sql(SQL)', 58, [['bootstrap', 'claimed_at']],
        ]]];
        yield 'journal seconds and datetime' => ['journal', [[
            ['UPDATE', 'journal', 'SET', 'at', '=', 'CAST', '(', 'UNIX_TIMESTAMP', '(', 'CURRENT_TIMESTAMP', '(', '3', ')', ')', 'AS', 'SIGNED', ')'],
            'Hilos\Database\Database::sql(SQL)', 63, [['journal', 'at']],
        ], [
            ['UPDATE', 'journal', 'SET', 'at', '=', 'NOW', '(', ')'],
            'Hilos\Database\Database::sql(SQL)', 64, [['journal', 'at']],
        ]]];
        yield 'identical sinks in multiple VALUES rows' => ['duplicates', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', 'NOW', '(', ')', ')', ',', '(', 'NOW', '(', ')', ')'],
            'Hilos\Database\Database::sql(SQL)', 69, [['event', 'at'], ['event', 'at']],
        ]]];
        yield 'named PHP arguments and named SQL cast parameter' => ['namedArguments', [[
            ['UPDATE', 'event', 'SET', 'at', '=', 'CAST', '(', ':moment', 'AS', 'SIGNED', ')'],
            'Hilos\Database\Database::sqlRun(params: [\':moment\' => (int)(seconds())], sql: SQL)', 74, [['event', 'at']],
        ]]];
        yield 'date and gmdate parameters' => ['dates', [[
            ['UPDATE', 'event', 'SET', 'at', '=', '?', ',', 'created_at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [date(\'Y-m-d H:i:s\'), gmdate(\'Y-m-d \' . \'H:i:s\')])',
            79, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'qualified execution and builtin' => ['qualified', [[
            ['UPDATE', 'event', 'SET', 'at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [\time()])', 84, [['event', 'at']],
        ]]];
        yield 'ordinal includes non-moment and WHERE but skips quoted question' => ['whereOrdinals', [[
            ['UPDATE', 'event', 'SET', 'duration', '=', '?', ',', 'at', '=', '(', '?', ')', 'WHERE', 'label', '=', "'?'", 'AND', 'id', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [5, time(), 7])', 89, [['event', 'at']],
        ]]];
        yield 'literal RHS remains proven with opaque parameters' => ['literalWithUnknownParameters', [[
            ['UPDATE', 'event', 'SET', 'at', '=', 'NOW', '(', ')', ',', 'created_at', '=', '?', 'WHERE', 'id', '=', '?'],
            'Hilos\Database\Database::sql(SQL, $parameters)', 94, [['event', 'at']],
        ]]];
        yield 'positional helper and PHP SQL datetime literal' => ['positionalDatetime', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ',', 'created_at', ')', 'VALUES', '(', '?', ',', '?', ')'],
            'Hilos\Database\Database::sql(SQL, [Hilos\Utils\Helpers\TimeHelper::getSqlDateTime(), \'2026-11-07 12:13:14\'])',
            99, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'named DateTime parameter' => ['namedTimestamp', [[
            ['UPDATE', 'event', 'SET', 'at', '=', ':at'],
            'Hilos\Database\Database::sql(SQL, [\'at\' => $date->getTimestamp()])', 104, [['event', 'at']],
        ]]];
        yield 'explicit positional key in a long inline array' => ['keyedList', [[
            ['UPDATE', 'event', 'SET', 'at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, array(0 => time()))', 109, [['event', 'at']],
        ]]];
        yield 'INSERT IGNORE literal positional and named writes' => ['insertIgnore', [[
            ['INSERT', 'IGNORE', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', 'NOW', '(', ')', ')'],
            'Hilos\Database\Database::sql(SQL)', 131, [['event', 'at']],
        ], [
            ['INSERT', 'IGNORE', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', '?', ')'],
            'Hilos\Database\Database::sql(SQL, [time()])', 132, [['event', 'at']],
        ], [
            ['INSERT', 'IGNORE', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', ':at', ')'],
            'Hilos\Database\Database::sql(SQL, [\'at\' => seconds()])', 133, [['event', 'at']],
        ]]];
        yield 'literal upsert retains VALUES and direct duplicate writes' => ['upsert', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', 'NOW', '(', ')', ')',
                'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'at', '=', 'NOW', '(', ')'],
            'Hilos\Database\Database::sql(SQL)', 138, [['event', 'at'], ['event', 'at']],
        ]]];
        yield 'bad VALUES survives a correct duplicate branch' => ['upsertValues', [[
            ['INSERT', 'IGNORE', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', 'NOW', '(', ')', ')',
                'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'at', '=', 'NULL'],
            'Hilos\Database\Database::sql(SQL)', 143, [['event', 'at']],
        ]]];
        yield 'correct VALUES does not hide bad duplicate assignments' => ['upsertDuplicate', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ',', 'created_at', ')', 'VALUES', '(', '0', ',', 'NULL', ')',
                'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'at', '=', 'NOW', '(', ')', ',', 'created_at', '=', 'UNIX_TIMESTAMP', '(', ')'],
            'Hilos\Database\Database::sql(SQL)', 148, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'positional ordinals span multiple rows non-moments and duplicate assignments' => ['upsertPositional', [[
            ['INSERT', 'IGNORE', 'INTO', 'event', '(', 'id', ',', 'at', ')', 'VALUES', '(', '?', ',', '?', ')',
                ',', '(', '?', ',', '?', ')', 'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'duration', '=', '?', ',', 'at', '=', '?',
                ',', 'created_at', '=', '?'],
            'Hilos\Database\Database::sql(SQL, [19, Hilos\Utils\Helpers\TimeHelper::nowMs(), 18, time(), time(), 0, time()])',
            153, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'named params retain repeated placeholders across upsert branches' => ['upsertNamed', [[
            ['INSERT', 'INTO', 'event', '(', 'id', ',', 'at', ',', 'created_at', ')', 'VALUES', '(', ':id', ',', ':at', ',', ':created', ')',
                'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'at', '=', ':changed', ',', 'created_at', '=', ':created'],
            'Hilos\Database\Database::sql(SQL, [\'changed\' => Hilos\Utils\Helpers\TimeHelper::nowMs(), \'id\' => time(), '
                . '\'created\' => Hilos\Utils\Helpers\TimeHelper::getSqlDateTime(), \'at\' => time()])',
            159, [['event', 'at'], ['event', 'created_at'], ['event', 'created_at']],
        ]]];
        yield 'named PHP args SQL case backticks and duplicate casts' => ['upsertNamedArguments', [[
            ['insert', 'ignore', 'into', '`event`', '(', '`AT`', ')', 'values', '(', '?', ')',
                'on', 'duplicate', 'key', 'update', '`At`', '=', 'CAST', '(', '?', 'AS', 'SIGNED', ')',
                ',', '`CrEaTeD_At`', '=', 'CURRENT_TIMESTAMP'],
            'Hilos\Database\Database::sqlRun(params: [Hilos\Utils\Helpers\TimeHelper::nowMs(), (int)(seconds())], sql: SQL)',
            165, [['event', 'at'], ['event', 'created_at']],
        ]]];
        yield 'all VALUES rows and duplicate assignments preserve occurrence counts' => ['upsertMultiple', [[
            ['INSERT', 'INTO', 'event', '(', 'at', ')', 'VALUES', '(', 'NOW', '(', ')', ')', ',', '(', 'NOW', '(', ')', ')',
                'ON', 'DUPLICATE', 'KEY', 'UPDATE', 'at', '=', 'NOW', '(', ')', ',', 'created_at', '=', 'CURRENT_TIMESTAMP', '(', '3', ')'],
            'Hilos\Database\Database::sql(SQL)', 171, [['event', 'at'], ['event', 'at'], ['event', 'at'], ['event', 'created_at']],
        ]]];
    }

    /**
     * @param string $method SQL writer method
     * @param list<array> $writes Expected SQL tokens, execution expression, line, sinks and optional PHP alias initializer
     */
    #[DataProvider('sqlWriters')]
    public function testExactSqlWriterReports(string $method, array $writes): void
    {
        $expected = [];
        foreach ($writes as $write) {
            [$sql, $call, $line, $columns] = $write;
            $texts = [];
            foreach (self::tokenTexts($call) as $token) {
                array_push($texts, ...($token === 'SQL' ? $sql : [$token]));
            }
            $initializer = isset($write[4]) ? self::tokenTexts($write[4]) : [];
            $fingerprint = hash('sha256', self::json([...$texts, ...$initializer]));
            $fragment = implode(' ', $texts);
            if ($initializer !== []) {
                $fragment .= ' [from ' . implode(' ', $initializer) . ']';
            }
            foreach ($columns as [$table, $column]) {
                $subject = self::sqlSubject('Writers.php', 'MomentSqlFixtures\Writers::' . $method, $table, $column);
                $expected[self::json(['MOMENT-WRITE', $subject, $fingerprint])][] = sprintf(
                    '%s/Sql/Writers.php:%d MOMENT-WRITE MomentSqlFixtures\Writers::%s -> %s.%s: %s',
                    self::ROOT, $line, $method, $table, $column, $fragment,
                );
            }
        }
        ksort($expected, SORT_STRING);
        $report = self::sqlScan();
        $actual = array_filter($report['reported'], static fn(string $key): bool =>
            json_decode(json_decode($key, true)[1], true)[2] === 'MomentSqlFixtures\Writers::' . $method, ARRAY_FILTER_USE_KEY);

        self::assertSame($expected, $actual);
        foreach (array_keys($expected) as $key) {
            self::assertContains(json_decode($key, true)[1], $report['subjects']);
        }
    }

    public function testSqlPositiveAndUnknownMatrixHasNoUnlistedFindings(): void
    {
        $expected = [];
        foreach (self::sqlWriters() as [$method]) {
            $expected[] = 'MomentSqlFixtures\Writers::' . $method;
        }
        $actual = [];
        foreach (array_keys(self::sqlScan()['reported']) as $key) {
            $actual[] = json_decode(json_decode($key, true)[1], true)[2];
        }
        sort($expected);
        $actual = array_values(array_unique($actual));
        sort($actual);

        self::assertSame($expected, $actual);
        self::assertContains(self::sqlSubject('Good.php', 'MomentSqlFixtures\Good::values', 'event', 'at'), self::sqlScan()['subjects']);
        self::assertNotContains(self::sqlSubject('Unknown.php', 'MomentSqlFixtures\Unknown::templates', 'event', 'at'), self::sqlScan()['subjects']);
    }

    /** INSERT modifiers preserve all proven subjects, including correct and unbound writes. */
    public function testSqlInsertModifiersKeepExactCurrentAndHistoricalCoverage(): void
    {
        $expected = [];
        $methods = [];
        foreach ([
            'Writers.php' => [
                'insertIgnore' => ['event' => ['at']],
                'upsert' => ['event' => ['at']],
                'upsertValues' => ['event' => ['at']],
                'upsertDuplicate' => ['event' => ['at', 'created_at']],
                'upsertPositional' => ['event' => ['at', 'created_at']],
                'upsertNamed' => ['event' => ['at', 'created_at']],
                'upsertNamedArguments' => ['event' => ['at', 'created_at']],
                'upsertMultiple' => ['event' => ['at', 'created_at']],
            ],
            'Good.php' => [
                'insertModifiers' => ['event' => ['at', 'created_at']],
                'insertJournal' => ['journal' => ['at']],
            ],
            'Unknown.php' => [
                'insertGrammar' => [],
                'insertBindings' => ['event' => ['at']],
            ],
        ] as $file => $writers) {
            foreach ($writers as $method => $tables) {
                $qualified = 'MomentSqlFixtures\\' . basename($file, '.php') . '::' . $method;
                $methods[] = $qualified;
                foreach ($tables as $table => $columns) {
                    foreach ($columns as $column) {
                        $expected[] = self::sqlSubject($file, $qualified, $table, $column);
                    }
                }
            }
        }
        sort($expected, SORT_STRING);
        $report = self::sqlScan();
        $subjects = array_values(array_filter($report['subjects'], static fn(string $subject): bool =>
            in_array(json_decode($subject, true)[2], $methods, true)));
        self::assertSame($expected, $subjects);

        $deleted = self::sqlSubject('Deleted.php', 'MomentSqlFixtures\Deleted::upsert', 'event', 'at');
        $outside = self::subject('other/backend/Writer.php', 'Other\Writer::upsert', 'at');
        $historical = [$deleted, $outside, ...$expected];
        $covered = MomentStorageSources::coveredSubjects($report['subjects'], $historical, [self::ROOT . '/Sql'], []);
        self::assertContains($deleted, $covered);
        self::assertNotContains($outside, $covered);
        foreach ($expected as $subject) {
            self::assertContains($subject, $covered);
        }
    }

    public function testSqlDoesNotGuessBetweenProjectDeclarationsSharingAPhysicalName(): void
    {
        $catalog = self::sqlCatalog();
        $catalog[] = [...$catalog[0], 'entity' => 'OtherProject\Event', 'declaration' => 'OtherProject\Event',
            'projectStorage' => 'A separate project owns this table'];
        $report = self::sqlScan($catalog);

        foreach ($report['subjects'] as $subject) {
            self::assertNotSame('event', json_decode(json_decode($subject, true)[3], true)[2]);
        }
        self::assertContains(self::sqlSubject('Writers.php', 'MomentSqlFixtures\Writers::provider', 'bootstrap', 'claimed_at'), $report['subjects']);
    }

    public function testSqlMountsOfOneDeclarationDoNotDuplicateFindingsAndMappedOnlyGatesPhp(): void
    {
        $catalog = self::sqlCatalog();
        foreach ($catalog as $entry) {
            if ($entry['table'] === 'event') {
                $catalog[] = [...$entry, 'entity' => 'Project\MountedEvent', 'mapped' => false];
            }
        }

        self::assertSame(self::sqlScan(), self::sqlScan($catalog));
        $catalog = array_map(static fn(array $entry): array => [...$entry, 'mapped' => false], self::sqlCatalog());
        self::assertSame(self::sqlScan(), self::sqlScan($catalog));
    }

    public function testSqlOneConcreteEntityRetainsSeparateColumnDeclarationSubjects(): void
    {
        $catalog = self::sqlCatalog();
        $catalog[1]['declaration'] = 'Project\ExtendedEvent';
        $method = 'MomentSqlFixtures\Writers::insert';
        $subjects = array_values(array_filter(self::sqlScan($catalog)['subjects'],
            static fn(string $subject): bool => json_decode($subject, true)[2] === $method));
        $expected = [
            self::sqlSubject('Writers.php', $method, 'event', 'at'),
            self::json(['source', self::ROOT . '/Sql/Writers.php', $method,
                self::json(['schema', 'Project\ExtendedEvent', 'event', 'created_at'])]),
        ];
        sort($expected);

        self::assertSame($expected, $subjects);
    }

    public function testSqlProjectChoiceExemptsOnlyOwnStorage(): void
    {
        $catalog = self::sqlCatalog();
        $catalog[0]['projectStorage'] = 'Explicit project SQL text';
        $report = self::sqlScan($catalog);
        self::assertNotContains(self::sqlSubject('Writers.php', 'MomentSqlFixtures\Writers::insert', 'event', 'at'), $report['subjects']);
        $catalog[0]['frameworkOwned'] = true;
        self::assertSame(self::sqlScan(), self::sqlScan($catalog));
    }

    public function testSqlFormattingAndImportAliasesDoNotChangeTheFingerprint(): void
    {
        $original = self::sqlVariant('Original');
        $formatting = self::sqlVariant('Formatting');

        self::assertCount(1, $original['reported']);
        self::assertSame($original['subjects'], $formatting['subjects']);
        self::assertSame(array_keys($original['reported']), array_keys($formatting['reported']));
        self::assertNotSame($original['reported'], $formatting['reported']);
    }

    public function testSqlAliasReplacementIsNewDebtAndWhereParametersAreNotWrites(): void
    {
        $original = self::sqlVariant('Original');
        $replacement = self::sqlVariant('Replacement');
        $rebound = self::sqlVariant('Rebound');
        $key = array_key_first($original['reported']);
        [$rule, $subject, $actual] = json_decode($key, true);
        $baseline = MomentStorageBaseline::fromJson(self::json(['version' => 1, 'records' => [[
            'rule' => $rule, 'subject' => $subject, 'actual' => $actual, 'count' => 1, 'owner' => 'HIL-1930',
        ]]]));

        self::assertSame($original['subjects'], $replacement['subjects']);
        self::assertCount(1, $replacement['reported']);
        self::assertNotSame(array_keys($original['reported']), array_keys($replacement['reported']));
        self::assertSame([], $baseline->reconcile($original['reported'], $original['subjects']));
        self::assertSame([
            ...array_values($replacement['reported'])[0],
            'moment storage baseline record ' . $key . ' (HIL-1930) is paid off — delete the record',
        ], $baseline->reconcile($replacement['reported'], $replacement['subjects']));
        self::assertSame([], $rebound['reported']);
        self::assertSame([], $rebound['subjects']);
    }

    /**
     * @param string $method Writer method
     * @param list<array> $writes Expected column/expression/line/optional initializer tuples
     * @param string $class Fixture class
     * @param string $file Fixture file
     */
    #[DataProvider('writers')]
    public function testExactWriterReports(string $method, array $writes, string $class, string $file): void
    {
        $class = str_contains($class, '\\') ? $class : 'MomentFixtures\\' . $class;
        $path = self::ROOT . '/' . $file;
        $expected = [];
        foreach ($writes as $write) {
            [$column, $expression, $line] = $write;
            $subject = self::subject($path, $class . '::' . $method, $column);
            $texts = self::tokenTexts($expression);
            $initializer = isset($write[3]) ? self::tokenTexts($write[3]) : [];
            $fingerprint = hash('sha256', self::json([...$texts, ...$initializer]));
            $fragment = implode(' ', $texts);
            if (isset($write[3])) {
                $fragment .= ' [from ' . implode(' ', self::tokenTexts($write[3])) . ']';
            }
            $expected[self::json(['MOMENT-WRITE', $subject, $fingerprint])][] = sprintf(
                '%s:%d MOMENT-WRITE %s::%s -> event.%s: %s', $path, $line, $class, $method, $column, $fragment,
            );
        }
        ksort($expected, SORT_STRING);
        $report = self::scan();
        $actual = array_filter($report['reported'], static function (string $key) use ($class, $method): bool {
            return json_decode(json_decode($key, true)[1], true)[2] === $class . '::' . $method;
        }, ARRAY_FILTER_USE_KEY);

        self::assertSame($expected, $actual);
        foreach (array_keys($expected) as $key) {
            self::assertContains(json_decode($key, true)[1], $report['subjects']);
        }
    }

    public function testGoodAndUnknownLookalikesAreSilentAndNoUnlistedWriterLeaksIn(): void
    {
        $expected = [];
        foreach (self::writers() as [$method, , $class]) {
            $expected[] = (str_contains($class, '\\') ? $class : 'MomentFixtures\\' . $class) . '::' . $method;
        }
        $actual = [];
        foreach (array_keys(self::scan()['reported']) as $key) {
            $actual[] = json_decode(json_decode($key, true)[1], true)[2];
        }
        sort($expected);
        $actual = array_values(array_unique($actual));
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /** Destructuring invalidates only written locals, without inferring the replacement value. */
    public function testDestructuringInvalidatesAliasesAndReceiverBindings(): void
    {
        $path = self::ROOT . '/Destructuring/backend/Writer.php';
        $class = 'MomentFixtures\\DestructuringWriter';
        $expected = [];
        $writes = [
            'brackets' => [
                ['at', '$entity->at = time()', 21],
                ['at', 'Hilos\\Database\\Database::sql(UPDATE event SET at = ?, [time()])', 22],
            ],
            'lists' => [
                ['at', '$entity->at = time()', 31],
                ['at', 'Hilos\\Database\\Database::sql(UPDATE event SET at = ?, [time()])', 32],
            ],
            'keyedTarget' => [['created_at', '$entity->created_at = $key', 114, '$key = time()']],
            'keyedListTarget' => [['created_at', '$entity->created_at = $key', 123, '$key = time()']],
            'reads' => [
                ['at', '$entity->at = $when', 133, '$when = time()'],
                ['at', '$entity->at = $when', 134, '$when = time()'],
                ['created_at', '$entity->created_at = time()', 135],
            ],
        ];
        foreach ($writes as $method => $occurrences) {
            foreach ($occurrences as $write) {
                [$column, $expression, $line] = $write;
                $subject = self::subject($path, $class . '::' . $method, $column);
                $texts = self::tokenTexts($expression);
                $initializer = isset($write[3]) ? self::tokenTexts($write[3]) : [];
                $fingerprint = hash('sha256', self::json([...$texts, ...$initializer]));
                $fragment = implode(' ', $texts);
                if ($initializer !== []) {
                    $fragment .= ' [from ' . implode(' ', $initializer) . ']';
                }
                $expected[self::json(['MOMENT-WRITE', $subject, $fingerprint])][] = sprintf(
                    '%s:%d MOMENT-WRITE %s::%s -> event.%s: %s', $path, $line, $class, $method, $column, $fragment,
                );
            }
        }
        ksort($expected, SORT_STRING);
        $subjects = [];
        foreach ([
            'brackets' => ['at'],
            'lists' => ['at'],
            'nestedBrackets' => ['at', 'created_at'],
            'nestedLists' => ['at', 'created_at'],
            'firstDestructuring' => ['at', 'created_at'],
            'keyedTarget' => ['at', 'created_at'],
            'keyedListTarget' => ['at', 'created_at'],
            'reads' => ['at', 'created_at'],
            'references' => ['at', 'created_at'],
        ] as $method => $columns) {
            foreach ($columns as $column) {
                $subjects[] = self::subject($path, $class . '::' . $method, $column);
            }
        }
        sort($subjects, SORT_STRING);
        $report = MomentStorageSourceRule::forRoots(dirname(__DIR__, 2), [self::ROOT], self::sqlCatalog(), [
            self::ROOT => ['AliasOriginal', 'AliasReplacement', 'AliasFormatting', 'Sql'],
        ]);
        $reported = array_filter($report['reported'], static fn(string $key): bool =>
            json_decode(json_decode($key, true)[1], true)[1] === $path, ARRAY_FILTER_USE_KEY);
        $covered = array_values(array_filter($report['subjects'], static fn(string $subject): bool =>
            json_decode($subject, true)[1] === $path));

        self::assertSame(['reported' => $expected, 'subjects' => $subjects], ['reported' => $reported, 'subjects' => $covered]);
    }

    /** Ordinary and keyword argument names delimit targets without erasing unrelated simple proofs. */
    public function testNamedArgumentsInvalidateOnlyAssignedLocalsAndKeepDirectSinks(): void
    {
        self::assertContextReport(self::ROOT . '/Destructuring/backend/Named.php', [
            'NamedDestructuringWriter::bracketsFirst' => [
                ['at', '$entity->at = time()', 25],
                ['at', 'Hilos\\Database\\Database::sql(UPDATE event SET at = ?, [time()])', 26],
            ],
            'NamedDestructuringWriter::nestedKeyed' => [
                ['created_at', '$entity->created_at = $key', 63, '$key = time()'],
            ],
            'NamedDestructuringWriter::readsAndUnrelated' => [
                ['at', '$entity->at = $when', 92, '$when = time()'],
                ['at', '$entity->at = $when', 93, '$when = time()'],
                ['at', 'Hilos\\Database\\Database::sql(UPDATE event SET at = ?, [$when])', 94, '$when = time()'],
            ],
            'NamedDestructuringWriter::directSinks' => [
                ['at', '$entity->at = time()', 111],
                ['created_at', '$entity->created_at = time()', 112],
                ['at', '$object->happenedAt = time()', 113],
                ['at', '$entity[\'at\'] = time()', 114],
            ],
            'NamedDestructuringWriter::keywordDirectSinks' => [
                ['at', '$entity->at = time()', 173],
                ['at', '$entity->at = time()', 175],
                ['at', '$entity->at = time()', 176],
                ['at', '$entity->at = time()', 177],
                ['at', '$entity->at = time()', 178],
                ['at', '$entity->at = time()', 179],
                ['at', '$entity->at = time()', 180],
                ['at', '$entity->at = time()', 181],
                ['at', '$entity->at = time()', 182],
                ['at', '$entity->at = time()', 183],
                ['at', '$entity->at = time()', 184],
                ['at', '$entity->at = time()', 185],
                ['at', '$entity->at = time()', 186],
            ],
            'NamedDestructuringWriter::keywordProofReads' => [
                ['at', '$entity->at = $when', 199, '$when = date(format: \'Y-m-d H:i:s\')'],
                ['at', '$entity->at = $when', 200, '$when = date(format: \'Y-m-d H:i:s\')'],
                ['created_at', '$entity->created_at = $other', 201, '$other = gmdate(format: \'Y-m-d H:i:s\')'],
                ['at', '$local->at = $read', 202, '$read = time()'],
                ['at', '$next->happenedAt = $read', 204, '$read = time()'],
                ['at', '$object->happenedAt = $read', 205, '$read = time()'],
            ],
            'NamedDestructuringWriter::keywordCallForms' => [
                ['at', '$entity->at = time()', 223],
                ['at', '$entity->at = time()', 224],
                ['at', '$entity->at = time()', 225],
                ['at', '$entity->at = time()', 226],
                ['at', '$entity->at = time()', 227],
                ['at', '$entity->at = time()', 227],
            ],
            'NamedDestructuringWriter::arrayLookalikes' => [
                ['at', '$entity->at = $when', 263, '$when = time()'],
            ],
            'NamedDestructuringWriter::stringCallForms' => [
                ['at', '$entity->at = time()', 268],
                ['at', '$entity->at = time()', 269],
                ['at', '$entity->at = time()', 270],
                ['at', '$entity->at = time()', 271],
            ],
            'NamedDestructuringWriter::docDirectSinks' => [
                ['at', '$entity->at = time()', 302],
                ['at', '$entity->at = time()', 303],
                ['at', '$entity->at = time()', 304],
                ['at', '$entity->at = time()', 305],
                ['at', '$entity->at = time()', 306],
                ['at', '$object->happenedAt = time()', 307],
                ['at', '$entity[\'at\'] = time()', 308],
            ],
            'NamedDestructuringWriter::docKeywordSinks' => [
                ['at', '$entity->at = time()', 315],
                ['at', '$entity->at = time()', 316],
                ['at', '$entity->at = time()', 317],
                ['at', '$entity->at = time()', 318],
                ['at', '$entity->at = time()', 319],
                ['at', '$entity->at = time()', 320],
                ['at', '$entity->at = time()', 321],
                ['at', '$entity->at = time()', 322],
                ['at', '$entity->at = time()', 323],
                ['at', '$entity->at = time()', 324],
            ],
            'NamedDestructuringWriter::docProofReadsAndRebindings' => [
                ['at', '$entity->at = $when', 336, '$when = date(format: \'Y-m-d H:i:s\')'],
                ['at', '$entity->at = $when', 337, '$when = date(format: \'Y-m-d H:i:s\')'],
                ['at', '$local->at = $read', 338, '$read = time()'],
                ['at', '$next->happenedAt = $read', 340, '$read = time()'],
                ['at', '$object->happenedAt = $read', 341, '$read = time()'],
            ],
            'NamedDestructuringWriter::docAnnotations' => [
                ['at', '$local->at = time()', 366],
                ['at', '$next->at = time()', 370],
            ],
            'NamedDestructuringWriter::docLookalikes' => [
                ['created_at', '$entity->created_at = time()', 392],
                ['at', '$entity->at = $when', 404, '$when = time()'],
            ],
        ], [
            'NamedDestructuringWriter::bracketsFirst' => ['at'],
            'NamedDestructuringWriter::listsLater' => ['at'],
            'NamedDestructuringWriter::bracketsLater' => ['at'],
            'NamedDestructuringWriter::listsFirst' => ['at'],
            'NamedDestructuringWriter::nestedKeyed' => ['at', 'created_at'],
            'NamedDestructuringWriter::nestedLists' => ['at', 'created_at'],
            'NamedDestructuringWriter::firstBindings' => ['at', 'created_at'],
            'NamedDestructuringWriter::readsAndUnrelated' => ['at'],
            'NamedDestructuringWriter::directRebinding' => ['at'],
            'NamedDestructuringWriter::directSinks' => ['at', 'created_at'],
            'NamedDestructuringWriter::keywordDirectSinks' => ['at', 'created_at'],
            'NamedDestructuringWriter::keywordProofReads' => ['at', 'created_at'],
            'NamedDestructuringWriter::keywordSingleAssignments' => ['at', 'created_at'],
            'NamedDestructuringWriter::keywordCallForms' => ['at'],
            'NamedDestructuringWriter::realBoundaries' => ['at', 'created_at'],
            'NamedDestructuringWriter::arrayLookalikes' => ['at'],
            'NamedDestructuringWriter::stringCallForms' => ['at'],
            'NamedDestructuringWriter::docDirectSinks' => ['at', 'created_at'],
            'NamedDestructuringWriter::docKeywordSinks' => ['at'],
            'NamedDestructuringWriter::docProofReadsAndRebindings' => ['at'],
            'NamedDestructuringWriter::docSingleAssignments' => ['at'],
            'NamedDestructuringWriter::docAnnotations' => ['at'],
            'NamedDestructuringWriter::docLookalikes' => ['at', 'created_at'],
        ]);
    }

    /** Direct targets and RHS bounds follow each arm without inferring branched receivers or values. */
    public function testTernaryArmsKeepExactDirectSinksAndConservativeProofs(): void
    {
        self::assertContextReport(self::ROOT . '/Destructuring/backend/Ternary.php', [
            'TernaryWriter::falseArm' => [
                ['at', '$entity->at = time()', 18],
            ],
            'TernaryWriter::trueArm' => [
                ['at', '$entity->at = time()', 23],
            ],
            'TernaryWriter::parenthesized' => [
                ['at', '($entity)->at = (time())', 28],
                ['created_at', '($entity)->created_at = (time())', 29],
            ],
            'TernaryWriter::arrays' => [
                ['at', '$entity[\'at\'] = time()', 34],
                ['created_at', '$entity[\'created_at\'] = time()', 34],
                ['at', '$entity[\'at\'] = time()', 35],
            ],
            'TernaryWriter::objects' => [
                ['at', '$object->happenedAt = time()', 40],
                ['at', '$this->object->happenedAt = time()', 40],
            ],
            'TernaryWriter::nested' => [
                ['at', '$entity->at = time()', 46],
                ['created_at', '$entity->created_at = time()', 46],
                ['at', '$entity->at = time()', 47],
                ['at', '$entity->at = time()', 48],
            ],
            'TernaryWriter::neighbors' => [
                ['at', '$entity->at = time()', 53],
                ['created_at', '$entity->created_at = time()', 53],
                ['at', '$entity->at = time()', 54],
                ['at', '$entity->at = time()', 55],
                ['created_at', '$entity->created_at = time()', 55],
            ],
        ], [
            'TernaryWriter::falseArm' => ['at'],
            'TernaryWriter::trueArm' => ['at'],
            'TernaryWriter::parenthesized' => ['at', 'created_at'],
            'TernaryWriter::arrays' => ['at', 'created_at'],
            'TernaryWriter::objects' => ['at'],
            'TernaryWriter::nested' => ['at', 'created_at'],
            'TernaryWriter::neighbors' => ['at', 'created_at'],
            'TernaryWriter::validTrue' => ['at'],
            'TernaryWriter::validFalse' => ['created_at'],
            'TernaryWriter::aliasTrue' => ['at'],
            'TernaryWriter::aliasFalse' => ['at'],
            'TernaryWriter::branchAlias' => ['at', 'created_at'],
            'TernaryWriter::branchedValue' => ['at', 'created_at'],
        ]);
    }

    /** @return iterable<string, array{string}> Expressions whose ordinary docs carry no proof */
    public static function documentedExpressions(): iterable
    {
        yield 'named arguments' => ['Named.php'];
        yield 'ternary arms' => ['Ternary.php'];
    }

    /**
     * Removing ordinary PHPDoc preserves exact findings, lines, multiplicity and current subjects.
     *
     * @param string $file Writer fixture filename
     */
    #[DataProvider('documentedExpressions')]
    public function testNamedCallDocsHaveTheSameReportAsPlainCode(string $file): void
    {
        $base = sys_get_temp_dir() . '/moment-named-' . bin2hex(random_bytes(8));
        mkdir($base . '/backend', recursive: true);
        try {
            $fixtures = dirname(__DIR__, 2) . '/' . self::ROOT;
            $source = file_get_contents($fixtures . '/Destructuring/backend/' . $file);
            file_put_contents($base . '/backend/Models.php', file_get_contents($fixtures . '/Models.php'));
            file_put_contents($base . '/backend/' . $file, $source);
            $commented = MomentStorageSourceRule::forRoots($base, ['backend'], self::sqlCatalog());
            $plain = '';
            foreach (token_get_all($source) as $token) {
                $plain .= is_array($token)
                    ? ($token[0] === T_DOC_COMMENT && !str_contains($token[1], '@var')
                        ? ' ' . str_repeat("\n", substr_count($token[1], "\n")) : $token[1])
                    : $token;
            }
            file_put_contents($base . '/backend/' . $file, $plain);
            self::assertSame($commented, MomentStorageSourceRule::forRoots($base, ['backend'], self::sqlCatalog()));
        } finally {
            foreach ([$file, 'Models.php'] as $written) {
                if (is_file($base . '/backend/' . $written)) {
                    unlink($base . '/backend/' . $written);
                }
            }
            rmdir($base . '/backend');
            rmdir($base);
        }
    }

    /** Attribute brackets and commas belong to metadata, not class or promoted parameter boundaries. */
    public function testAttributedPromotedPropertiesKeepExactVisibilityReportsAndCoverage(): void
    {
        self::assertContextReport(self::ROOT . '/Visibility/Attributes.php', [
            'AttributedChild::inherited' => [['at', '$private->happenedAt = time()', 65]],
            'AttributedWriter::inaccessible' => [
                ['at', '$private->happenedAt = time()', 104],
                ['at', '$protected->happenedAt = time()', 105],
                ['at', '$later->happenedAt = time()', 106],
            ],
            'AttributedWriter::subsequent' => [['at', '$entity->at = time()', 111]],
        ], [
            'AttributedChild::inherited' => ['at'],
            'AttributedWriter::inaccessible' => ['at'],
            'AttributedWriter::subsequent' => ['at'],
        ]);
    }

    /** Only inaccessible real properties reach a proven direct magic setter in the caller's scope. */
    public function testObjectPropertyVisibilityKeepsExactReportsAndCoverage(): void
    {
        $path = self::ROOT . '/Visibility/Writer.php';
        $expected = [];
        foreach ([
            'DirectVisibilityWriter::write' => [
                ['$protected->happenedAt = time()', 324],
                ['$private->happenedAt = time()', 325],
                ['$child->happenedAt = time()', 326],
            ],
            'PrivateChild::inherited' => [
                ['$this->happenedAt = time()', 51],
                ['$object->happenedAt = time()', 52],
            ],
            'TraitPrivateChild::inherited' => [['$this->happenedAt = time()', 129]],
            'VisibilityWriter::native' => [
                ['$protected->happenedAt = time()', 189],
                ['$private->happenedAt = time()', 190],
                ['$private->happenedAt = time()', 191],
            ],
            'VisibilityWriter::constructed' => [
                ['$protected->happenedAt = time()', 197],
                ['$private->happenedAt = time()', 199],
                ['$this->object->happenedAt = time()', 200],
            ],
            'VisibilityWriter::inherited' => [
                ['$protected->happenedAt = time()', 205],
                ['$private->happenedAt = time()', 206],
            ],
            'VisibilityWriter::traits' => [
                ['$protected->happenedAt = time()', 211],
                ['$private->happenedAt = time()', 212],
                ['$child->happenedAt = time()', 213],
                ['$privateChild->happenedAt = time()', 214],
            ],
            'VisibilityWriter::promoted' => [['$private->happenedAt = time()', 219]],
            'VisitingWriter::visit' => [['$object->happenedAt = time()', 283]],
            'UnusedWriter::write' => [
                ['$entity->at = time()', 334],
                ['$magic->happenedAt = time()', 335],
            ],
        ] as $method => $writes) {
            $qualified = 'MomentFixtures\\' . $method;
            $subject = self::subject($path, $qualified, 'at');
            foreach ($writes as [$expression, $line]) {
                $texts = self::tokenTexts($expression);
                $fingerprint = hash('sha256', self::json($texts));
                $expected[self::json(['MOMENT-WRITE', $subject, $fingerprint])][] = sprintf(
                    '%s:%d MOMENT-WRITE %s -> event.at: %s', $path, $line, $qualified, implode(' ', $texts),
                );
            }
        }
        ksort($expected, SORT_STRING);
        $subjects = [];
        foreach ([
            'DirectVisibilityWriter::write', 'DirectProtected::__set', 'DirectPrivate::__set',
            'PrivateChild::inherited', 'TraitPrivateChild::inherited', 'VisibilityWriter::native',
            'VisibilityWriter::constructed', 'VisibilityWriter::inherited', 'VisibilityWriter::traits',
            'VisibilityWriter::promoted', 'VisibilityWriter::valid', 'VisitingWriter::visit', 'UnusedWriter::write',
        ] as $method) {
            $subjects[] = self::subject($path, 'MomentFixtures\\' . $method, 'at');
        }
        $subjects[] = self::subject($path, 'MomentFixtures\\AmbiguousPrivate::__set', 'at');
        $subjects[] = self::subject($path, 'MomentFixtures\\AmbiguousPrivate::__set', 'created_at');
        $subjects[] = self::subject($path, 'MomentFixtures\\DirectSetter::__set', 'at');
        sort($subjects, SORT_STRING);
        $report = MomentStorageSourceRule::forRoots(dirname(__DIR__, 2), [self::ROOT], self::catalog(), [
            self::ROOT => ['AliasOriginal', 'AliasReplacement', 'AliasFormatting', 'Destructuring', 'Sql'],
        ]);
        $reported = array_filter($report['reported'], static fn(string $key): bool =>
            json_decode(json_decode($key, true)[1], true)[1] === $path, ARRAY_FILTER_USE_KEY);
        $current = array_values(array_filter($report['subjects'], static fn(string $subject): bool =>
            json_decode($subject, true)[1] === $path));
        self::assertSame(['reported' => $expected, 'subjects' => $subjects], ['reported' => $reported, 'subjects' => $current]);

        $historical = self::subject($path, 'MomentFixtures\\PrivateProperty::own', 'at');
        $deleted = self::subject(self::ROOT . '/Visibility/Deleted.php', 'MomentFixtures\\Deleted::write', 'at');
        $outside = self::subject('other/backend/Writer.php', 'Other\\Writer::write', 'at');
        $covered = MomentStorageSources::coveredSubjects($current, [$historical, $deleted, $outside], [self::ROOT], []);
        $expectedCoverage = [$historical, $deleted, ...$subjects];
        sort($expectedCoverage, SORT_STRING);
        self::assertSame($expectedCoverage, $covered);
    }

    public function testCatalogOptOutAndDbOnlyMetadataControlPhpSinks(): void
    {
        $catalog = self::catalog();
        $catalog[0]['mapped'] = false;
        $catalog[1]['projectStorage'] = 'Project stores explicit SQL text';
        $report = self::scan($catalog);

        self::assertSame([], $report['reported']);
        self::assertSame([], $report['subjects']);
    }

    public function testDifferentDeclarationsSharingATableKeepDistinctSinkIdentity(): void
    {
        $catalog = self::catalog();
        $catalog[] = [...$catalog[0], 'declaration' => 'OtherProject\\Event', 'table' => 'event'];
        $report = self::scan($catalog);
        $method = 'MomentFixtures\\Bad::native';
        $subjects = array_values(array_filter($report['subjects'], static fn(string $subject): bool => json_decode($subject)[2] === $method));
        $expected = [
            self::subject(self::ROOT . '/Bad.php', $method, 'at'),
            self::json(['source', self::ROOT . '/Bad.php', $method, '["schema","OtherProject\\\\Event","event","at"]']),
        ];
        sort($expected);

        self::assertSame($expected, $subjects);
    }

    public function testReportsReconcileWithExactBaselineAndPreserveOccurrenceGrowth(): void
    {
        $report = self::scan();
        $records = [];
        foreach ($report['reported'] as $key => $occurrences) {
            [$rule, $subject, $actual] = json_decode($key, true);
            $records[] = ['rule' => $rule, 'subject' => $subject, 'actual' => $actual, 'count' => count($occurrences), 'owner' => 'HIL-1930'];
        }
        $baseline = MomentStorageBaseline::fromJson(self::json(['version' => 1, 'records' => $records]));

        self::assertSame([], $baseline->reconcile($report['reported'], $report['subjects']));
        $first = array_key_first($report['reported']);
        $report['reported'][$first][] = 'one more identical occurrence';
        self::assertSame(['one more identical occurrence'], $baseline->reconcile($report['reported'], $report['subjects']));
    }

    public function testCoverageIncludesValidSinksWithoutInventingUnknownReceivers(): void
    {
        $subjects = self::scan()['subjects'];

        self::assertContains(self::subject(self::ROOT . '/Good.php', 'MomentFixtures\\Good::valid', 'at'), $subjects);
        self::assertNotContains(self::subject(self::ROOT . '/Unknown.php', 'MomentFixtures\\Unknown::receivers', 'at'), $subjects);
        self::assertContains(self::subject(self::ROOT . '/Good.php', 'MomentFixtures\\Good::switchValues', 'at'), $subjects);
        self::assertContains(self::subject(self::ROOT . '/Good.php', 'MomentFixtures\\Good::switchValues', 'created_at'), $subjects);
        self::assertSame([
            self::subject(self::ROOT . '/Unknown.php', 'MomentFixtures\\Unknown::switchReceivers', 'at'),
        ], array_values(array_filter($subjects,
            static fn(string $subject): bool => json_decode($subject, true)[2] === 'MomentFixtures\\Unknown::switchReceivers')));
    }

    public function testAliasInitializerReplacementIsNewDebtAtTheSamePathMethodAndCount(): void
    {
        $original = self::aliasScan('AliasOriginal');
        $replacement = self::aliasScan('AliasReplacement');
        $key = array_key_first($original['reported']);
        [$rule, $subject, $actual] = json_decode($key, true);
        $baseline = MomentStorageBaseline::fromJson(self::json(['version' => 1, 'records' => [[
            'rule' => $rule, 'subject' => $subject, 'actual' => $actual, 'count' => 1, 'owner' => 'HIL-1930',
        ]]]));

        self::assertSame($original['subjects'], $replacement['subjects']);
        self::assertNotSame(array_keys($original['reported']), array_keys($replacement['reported']));
        self::assertSame([
            ...array_values($replacement['reported'])[0],
            'moment storage baseline record ' . $key . ' (HIL-1930) is paid off — delete the record',
        ], $baseline->reconcile($replacement['reported'], $replacement['subjects']));
    }

    public function testWhitespaceCommentsAndLineNumbersDoNotChangeTheFingerprint(): void
    {
        $original = self::aliasScan('AliasOriginal');
        $formatting = self::aliasScan('AliasFormatting');

        self::assertCount(1, $original['reported']);
        self::assertSame($original['subjects'], $formatting['subjects']);
        self::assertSame(array_keys($original['reported']), array_keys($formatting['reported']));
        self::assertNotSame($original['reported'], $formatting['reported']);
    }

    public function testFrameworkOwnedMomentsCannotBeSilencedByAProjectChoice(): void
    {
        $catalog = self::catalog();
        $catalog[0]['projectStorage'] = 'Invalid override must not exempt framework storage';
        $catalog[0]['frameworkOwned'] = true;

        self::assertSame(self::scan(), self::scan($catalog));
    }

    public function testSyntaxFailureCannotBecomeAnEmptySuccessfulScan(): void
    {
        $this->expectException(ParseError::class);

        (new MomentStorageSourceRule(new SourceIndex(), self::catalog()))->analyze(['backend/Broken.php' => '<?php class {']);
    }

    /** Selected roots are mandatory even though absent demos are optional during discovery. */
    public function testMissingSelectedRootCannotBecomeAnEmptySuccessfulScan(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unreadable moment source root: backend');

        MomentStorageSourceRule::forRoots(__FILE__, ['backend'], self::catalog());
    }

    /** Deleted sinks remain covered only inside the successfully checked source domain. */
    public function testFullSourceCoverageRetiresDeletedSinksButLeavesOtherDomainsAlone(): void
    {
        $schema = self::json(['schema', self::ENTITY, 'event', 'at']);
        $current = self::json(['source', 'framework/backend/Writer.php', 'Writer::run', $schema]);
        $deleted = self::json(['source', 'framework/backend/Deleted.php', 'Deleted::removed', $schema]);
        $demoDeleted = self::json(['source', 'demo/example/backend/Deleted.php', 'Deleted::removed', $schema]);
        $outside = [
            $schema,
            self::json(['source', 'demo/absent/backend/Writer.php', 'Writer::run', $schema]),
            self::json(['source', 'framework/backend-other/Writer.php', 'Writer::run', $schema]),
            self::json(['source', 'framework/backend/Database/Migration/Change/Old.php', 'Writer::run', $schema]),
            self::json(['source', 'demo/example/backend/vendor/Writer.php', 'Writer::run', $schema]),
            self::json(['source', 'demo/example/backend/generated/Writer.php', 'Writer::run', $schema]),
        ];
        $historical = [$current, $deleted, $demoDeleted, ...$outside];
        $covered = MomentStorageSources::coveredSubjects([$current], $historical,
            ['framework/backend', 'demo/example/backend'], [
                'framework/backend' => ['Database/Migration/Change'], 'demo/example/backend' => ['generated'],
            ]);
        $expected = [$current, $deleted, $demoDeleted];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $covered);

        $records = array_map(static fn(string $subject): array => [
            'rule' => 'MOMENT-WRITE', 'subject' => $subject, 'actual' => str_repeat('a', 64), 'count' => 1, 'owner' => 'HIL-1930',
        ], [$deleted, $demoDeleted]);
        $baseline = MomentStorageBaseline::fromJson(self::json(['version' => 1, 'records' => $records]));
        $findings = array_map(static fn(string $subject): string => self::json(['MOMENT-WRITE', $subject, str_repeat('a', 64)]),
            [$deleted, $demoDeleted]);
        sort($findings, SORT_STRING);
        self::assertSame(array_map(static fn(string $key): string =>
            'moment storage baseline record ' . $key . ' (HIL-1930) is paid off — delete the record', $findings),
            $baseline->reconcile([], $covered));
        self::assertSame([], MomentStorageSources::coveredSubjects([], $historical, [], []));
    }

    /** A broken production registration fails without loading the application or test autoload. */
    public function testInvalidProjectMetadataRefusesDiscoveryAndUnregistersItsLoader(): void
    {
        $base = sys_get_temp_dir() . '/moment-catalog-' . bin2hex(random_bytes(8));
        mkdir($base . '/framework/backend', recursive: true);
        mkdir($base . '/demo/example/backend', recursive: true);
        file_put_contents($base . '/demo/example/composer.json', self::json([
            'autoload' => ['psr-4' => ['MomentCatalogMissing\\' => 'backend/']],
            'autoload-dev' => ['psr-4' => ['MustNotLoad\\' => 'missing-test-autoload/']],
        ]));
        file_put_contents($base . '/demo/example/backend/Hilos.php', <<<'PHP'
<?php
namespace MomentCatalogMissing;
use Hilos\Hilos as HilosFacade;
final class Hilos extends HilosFacade
{
    protected const ?string BACKUP_CATALOG = MissingCatalog::class;
}
PHP);
        $autoloaders = spl_autoload_functions();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid BACKUP_CATALOG metadata for MomentCatalogMissing\\Hilos');

        try {
            MomentStorageSources::forRepository($base);
        } finally {
            self::assertSame($autoloaders, spl_autoload_functions());
            self::assertFalse(class_exists('MomentCatalogMissing\\Hilos', false), 'The application facade is never loaded.');
            unlink($base . '/demo/example/backend/Hilos.php');
            unlink($base . '/demo/example/composer.json');
            foreach (['demo/example/backend', 'demo/example', 'demo', 'framework/backend', 'framework'] as $directory) {
                rmdir($base . '/' . $directory);
            }
            rmdir($base);
        }
    }

    /**
     * @param string $path Fixture path within the shared model tree
     * @param array<string, list<array>> $writes Exact column/expression/line/optional initializer occurrences by method
     * @param array<string, list<string>> $methods Complete current subject columns by method
     */
    private static function assertContextReport(string $path, array $writes, array $methods): void
    {
        $expected = [];
        foreach ($writes as $method => $occurrences) {
            $qualified = 'MomentFixtures\\' . $method;
            foreach ($occurrences as $write) {
                [$column, $expression, $line] = $write;
                $texts = self::tokenTexts($expression);
                $initializer = isset($write[3]) ? self::tokenTexts($write[3]) : [];
                $subject = self::subject($path, $qualified, $column);
                $fingerprint = hash('sha256', self::json([...$texts, ...$initializer]));
                $fragment = implode(' ', $texts);
                if ($initializer !== []) {
                    $fragment .= ' [from ' . implode(' ', $initializer) . ']';
                }
                $expected[self::json(['MOMENT-WRITE', $subject, $fingerprint])][] = sprintf(
                    '%s:%d MOMENT-WRITE %s -> event.%s: %s', $path, $line, $qualified, $column, $fragment,
                );
            }
        }
        ksort($expected, SORT_STRING);
        $subjects = [];
        foreach ($methods as $method => $columns) {
            foreach ($columns as $column) {
                $subjects[] = self::subject($path, 'MomentFixtures\\' . $method, $column);
            }
        }
        sort($subjects, SORT_STRING);
        $report = MomentStorageSourceRule::forRoots(dirname(__DIR__, 2), [self::ROOT], self::sqlCatalog(), [
            self::ROOT => ['AliasOriginal', 'AliasReplacement', 'AliasFormatting', 'Sql'],
        ]);
        $reported = array_filter($report['reported'], static fn(string $key): bool =>
            json_decode(json_decode($key, true)[1], true)[1] === $path, ARRAY_FILTER_USE_KEY);
        $current = array_values(array_filter($report['subjects'], static fn(string $subject): bool =>
            json_decode($subject, true)[1] === $path));
        self::assertSame(['reported' => $expected, 'subjects' => $subjects], ['reported' => $reported, 'subjects' => $current]);

        $historical = self::subject($path, 'MomentFixtures\\Historical::write', 'at');
        $deleted = self::subject(dirname($path) . '/Deleted.php', 'MomentFixtures\\Deleted::write', 'at');
        $outside = self::subject('other/backend/Writer.php', 'Other\\Writer::write', 'at');
        $covered = MomentStorageSources::coveredSubjects($current, [$historical, $deleted, $outside], [dirname($path)], []);
        $expectedCoverage = [$historical, $deleted, ...$subjects];
        sort($expectedCoverage, SORT_STRING);
        self::assertSame($expectedCoverage, $covered);
    }

    /**
     * @param string $variant Isolated tree with the same repository-relative writer identity
     * @return array Exact source report
     */
    private static function aliasScan(string $variant): array
    {
        return MomentStorageSourceRule::forRoots(dirname(__DIR__, 2) . '/' . self::ROOT . '/' . $variant, ['backend'], self::catalog());
    }

    /**
     * @param ?array $catalog Optional normalized metadata matrix
     * @return array Exact scanner report
     */
    private static function scan(?array $catalog = null): array
    {
        return MomentStorageSourceRule::forRoots(dirname(__DIR__, 2), [self::ROOT], $catalog ?? self::catalog(), [
            self::ROOT => ['AliasOriginal', 'AliasReplacement', 'AliasFormatting', 'Destructuring', 'Sql', 'Visibility'],
        ]);
    }

    /**
     * @param ?array $catalog Optional normalized SQL catalog
     * @return array Canonical SQL report
     */
    private static function sqlScan(?array $catalog = null): array
    {
        return MomentStorageSourceRule::forRoots(dirname(__DIR__, 2), [self::ROOT . '/Sql'], $catalog ?? self::sqlCatalog(), [
            self::ROOT . '/Sql' => ['Variants'],
        ]);
    }

    /**
     * @param string $variant Isolated tree with stable repository-relative identity
     * @return array Canonical SQL report
     */
    private static function sqlVariant(string $variant): array
    {
        return MomentStorageSourceRule::forRoots(dirname(__DIR__, 2) . '/' . self::ROOT . '/Sql/Variants/' . $variant,
            ['backend'], self::sqlCatalog());
    }

    /** @return list<array> SQL includes both DB-only Entity columns and independent provider columns */
    private static function sqlCatalog(): array
    {
        $at = self::catalog()[0];

        return [
            ...array_slice(self::catalog(), 0, 3),
            [...$at, 'entity' => null, 'declaration' => 'MomentFixtures\BootstrapProvider', 'table' => 'bootstrap',
                'column' => 'claimed_at', 'mapped' => false, 'frameworkOwned' => true],
            [...$at, 'entity' => null, 'declaration' => 'MomentFixtures\JournalProvider', 'table' => 'journal',
                'mapped' => false, 'frameworkOwned' => true],
            [...$at, 'entity' => 'MomentFixtures\OwnEvent', 'declaration' => 'MomentFixtures\OwnEvent', 'table' => 'own_event',
                'projectStorage' => 'Explicit project SQL datetime'],
        ];
    }

    /**
     * @param string $file SQL fixture filename
     * @param string $method Qualified writer
     * @param string $table Physical table
     * @param string $column Physical column
     * @return string Exact canonical source subject
     */
    private static function sqlSubject(string $file, string $method, string $table, string $column): string
    {
        $declaration = match ($table) {
            'bootstrap' => 'MomentFixtures\BootstrapProvider',
            'journal' => 'MomentFixtures\JournalProvider',
            default => self::ENTITY,
        };

        return self::json(['source', self::ROOT . '/Sql/' . $file, $method, self::json(['schema', $declaration, $table, $column])]);
    }

    /** @return list<array> Normalized MomentColumn descriptors for fixtures only */
    private static function catalog(): array
    {
        $at = ['entity' => self::ENTITY, 'declaration' => self::ENTITY, 'table' => 'event', 'column' => 'at',
            'mapped' => true, 'frameworkOwned' => false, 'projectStorage' => null];

        return [
            $at,
            [...$at, 'column' => 'created_at'],
            [...$at, 'column' => 'unmapped', 'mapped' => false],
            [...$at, 'entity' => 'MomentFixtures\\OtherEntity', 'declaration' => 'MomentFixtures\\OtherEntity',
                'column' => 'at', 'projectStorage' => 'Explicit project SQL datetime'],
        ];
    }

    /**
     * @param string $path Repository-relative fixture path
     * @param string $method Fully qualified writer
     * @param string $column Physical column
     * @return string Canonical source subject containing a string schema subject
     */
    private static function subject(string $path, string $method, string $column): string
    {
        return self::json(['source', $path, $method, self::json(['schema', self::ENTITY, 'event', $column])]);
    }

    /**
     * @param string $expression Expected expression with qualified class names, independent of the detector
     * @return list<string> Significant PHP token texts, retaining token boundaries in the fingerprint
     */
    private static function tokenTexts(string $expression): array
    {
        $texts = [];
        foreach (token_get_all('<?php ' . $expression) as $token) {
            if (!is_array($token)) {
                $texts[] = $token;
            } elseif (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $texts[] = $token[1];
            }
        }

        return $texts;
    }

    /**
     * @param array $tuple Expected structural tuple
     * @return string Exact canonical JSON
     */
    private static function json(array $tuple): string
    {
        return json_encode($tuple, self::JSON_FLAGS);
    }
}
