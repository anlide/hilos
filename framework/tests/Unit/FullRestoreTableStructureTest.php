<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\CheckShape;
use Hilos\Backup\FullRestore\ColumnShape;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FullRestoreTableStructureTest extends TestCase
{
    /** Chat 166 migration body, HIL-1886 */
    private const string SILENCE_PERIOD_SOURCE = <<<'SQL'
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `public_id` CHAR(32) NOT NULL,
        `started_at` BIGINT NOT NULL,
        `ended_at` BIGINT NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_status_silence_period_public` (`public_id`),
        KEY `idx_status_silence_period_ended` (`ended_at`, `id`),
        CONSTRAINT `ck_status_silence_period_bounds` CHECK (`ended_at` > `started_at`)
        SQL;
    /** The same table as MariaDB printed it on the HIL-1886 stand */
    private const string SILENCE_PERIOD_PHYSICAL = <<<'SQL'
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `public_id` char(32) NOT NULL,
          `started_at` bigint(20) NOT NULL,
          `ended_at` bigint(20) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_status_silence_period_public` (`public_id`),
          KEY `idx_status_silence_period_ended` (`ended_at`,`id`),
          CONSTRAINT `ck_status_silence_period_bounds` CHECK (`ended_at` > `started_at`)
        SQL;
    /** Framework i18n reflow stub body */
    private const string I18N_REFLOW_SOURCE = <<<'SQL'
        `id` TINYINT UNSIGNED NOT NULL,
        `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (`id`),
        CONSTRAINT `chk_i18n_reflow_one_row` CHECK (`id` = 1)
        SQL;
    /** The same table as MariaDB printed it on the HIL-1886 stand */
    private const string I18N_REFLOW_PHYSICAL = <<<'SQL'
          `id` tinyint(3) unsigned NOT NULL,
          `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          PRIMARY KEY (`id`),
          CONSTRAINT `chk_i18n_reflow_one_row` CHECK (`id` = 1)
        SQL;

    /**
     * @param string $source Ordinary migration declaration
     * @param string $physical Equivalent MariaDB SHOW CREATE column
     */
    #[DataProvider('booleanColumns')]
    public function testBooleanAliasesMatchPhysicalIntegerDefaults(string $source, string $physical): void
    {
        $this->assertSame($this->structure('value ' . $physical)->fingerprint(), $this->structure('value ' . $source)->fingerprint());
    }

    /** @return iterable<string, array{string, string}> BOOLEAN/BOOL, literal aliases and nullable defaults */
    public static function booleanColumns(): iterable
    {
        yield 'false' => ['BOOLEAN NOT NULL DEFAULT FALSE', 'TINYINT(1) NOT NULL DEFAULT 0'];
        yield 'true' => ['BOOL NOT NULL DEFAULT TRUE', 'TINYINT(1) NOT NULL DEFAULT 1'];
        yield 'nullable true' => ['BOOLEAN DEFAULT TRUE', 'TINYINT(1) DEFAULT 1'];
        yield 'nullable implicit null' => ['BOOL', 'TINYINT(1) DEFAULT NULL'];
        yield 'numeric default' => ['BOOLEAN DEFAULT 0', 'TINYINT(1) DEFAULT 0'];
        yield 'integer literal alias' => ['INT DEFAULT TRUE', 'INT(11) DEFAULT 1'];
        yield 'zerofill width' => ['BOOLEAN ZEROFILL DEFAULT FALSE', 'TINYINT(1) UNSIGNED ZEROFILL DEFAULT 0'];
    }

    /** @param string $different Materially distinct live column or literal kind */
    #[DataProvider('differentBooleanColumns')]
    public function testBooleanNormalizationRetainsMaterialDifferences(string $different): void
    {
        $this->assertNotSame($this->structure('value BOOLEAN NOT NULL DEFAULT FALSE')->fingerprint(),
            $this->structure('value ' . $different)->fingerprint());
    }

    /** @return iterable<string, array{string}> Default, nullability, type and quoted string differences */
    public static function differentBooleanColumns(): iterable
    {
        yield 'true default' => ['TINYINT(1) NOT NULL DEFAULT 1'];
        yield 'nullable' => ['TINYINT(1) DEFAULT 0'];
        yield 'larger integer' => ['SMALLINT NOT NULL DEFAULT 0'];
        yield 'quoted false' => ["TINYINT(1) NOT NULL DEFAULT 'FALSE'"];
        yield 'quoted true' => ["TINYINT(1) NOT NULL DEFAULT 'TRUE'"];
    }

    /**
     * @param string $name Carrier column name
     * @param string $source Authored MariaDB alias
     * @param string $physical Equivalent SHOW CREATE type and attributes
     * @param bool $nullable Expected SQL nullability
     * @param ?string $default Exact scalar signature, retaining literal kind
     * @param ?string $comment Expected nonempty comment
     */
    #[DataProvider('jsonColumns')]
    public function testMariaDbJsonAliasMatchesTheExactPhysicalShape(
        string $name,
        string $source,
        string $physical,
        bool $nullable,
        ?string $default,
        ?string $comment = null,
    ): void {
        $options = 'CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin';
        $alias = $this->structure($name . ' ' . $source, $options, mariaDbJsonAlias: true);
        $attributes = [
            'charset' => 'utf8mb4',
            'check' => '[["word","json_valid"],["word","("],["word","' . $name . '"],["word",")"]]',
            'collation' => 'utf8mb4_bin',
        ];
        if ($comment !== null) {
            $attributes['comment'] = $comment;
        }
        $this->assertEquals(new ColumnShape('LONGTEXT[]', $nullable, $default, $attributes), $alias->columns[$name]);
        $this->assertSame('utf8mb4_nopad_bin', $alias->options['collation']);
        $this->assertSame($this->structure($name . ' ' . $physical, $options)->fingerprint(), $alias->fingerprint());
    }

    /** @return iterable<string, array> Both carriers and scalar defaults, independent of table collation */
    public static function jsonColumns(): iterable
    {
        yield 'operation required' => ['circle_user_ids', 'JSON NOT NULL',
            'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(circle_user_ids))', false, null];
        yield 'assignment required' => ['payload_json', 'JSON NOT NULL',
            'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(payload_json))', false, null];
        yield 'implicit nullable' => ['payload_json', 'JSON',
            'LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(payload_json))', true, '[["word","null"]]'];
        yield 'explicit nullable' => ['payload_json', 'JSON NULL DEFAULT NULL',
            'LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(payload_json))', true, '[["word","null"]]'];
        yield 'json text and comment' => ['payload_json', "JSON NOT NULL DEFAULT '{\"Case\":1}' COMMENT 'Keep Case'",
            "LONGTEXT COLLATE utf8mb4_bin NOT NULL DEFAULT '{\"Case\":1}' COMMENT 'Keep Case' CHECK (json_valid(payload_json))",
            false, '[["string","{\\"Case\\":1}"]]', 'Keep Case'];
        yield 'string null' => ['payload_json', "JSON DEFAULT 'NULL'",
            "LONGTEXT COLLATE utf8mb4_bin DEFAULT 'NULL' CHECK (json_valid(payload_json))", true, '[["string","NULL"]]'];
        yield 'numeric scalar' => ['payload_json', 'JSON DEFAULT 1',
            'LONGTEXT COLLATE utf8mb4_bin DEFAULT 1 CHECK (json_valid(payload_json))', true, '[["word","1"]]'];
        yield 'explicit self check' => ['payload_json', 'json NOT NULL CHECK (JSON_VALID(`PAYLOAD_JSON`))',
            'LONGTEXT COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(payload_json))', false, null];
    }

    /** Schema and table character sets do not override MariaDB JSON's fixed character pair. */
    public function testJsonAliasDoesNotInheritSchemaOrTableCharacterOptions(): void
    {
        $defaults = new StructureDefaults('InnoDB', 'latin1', 'latin1_swedish_ci',
            ['latin1' => 'latin1_swedish_ci', 'ascii' => 'ascii_general_ci'],
            charsetByCollation: ['utf8mb4_bin' => 'utf8mb4'], mariaDbJsonAlias: true);
        $alias = TableStructure::read('CREATE TABLE t (payload_json JSON) CHARSET=ascii', 'target', $defaults);
        $physical = TableStructure::read('CREATE TABLE t (payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
            . ' DEFAULT NULL CHECK (json_valid(payload_json))) CHARSET=ascii', 'target', $defaults);
        $this->assertSame('ascii', $alias->options['charset']);
        $this->assertSame('utf8mb4', $alias->columns['payload_json']->attributes['charset']);
        $this->assertSame('utf8mb4_bin', $alias->columns['payload_json']->attributes['collation']);
        $this->assertSame($physical->fingerprint(), $alias->fingerprint());
    }

    /** @param string $physical A material change must remain visible after alias normalization */
    #[DataProvider('differentJsonColumns')]
    public function testJsonAliasRetainsMaterialDifferences(string $physical): void
    {
        $this->assertNotSame($this->structure('payload_json JSON NOT NULL', mariaDbJsonAlias: true)->fingerprint(),
            $this->structure('payload_json ' . $physical)->fingerprint());
    }

    /** @return iterable<string, array{string}> Check, charset, collation, nullability and default differences */
    public static function differentJsonColumns(): iterable
    {
        yield 'missing check' => ['LONGTEXT COLLATE utf8mb4_bin NOT NULL'];
        yield 'table collation' => ['LONGTEXT NOT NULL CHECK (json_valid(payload_json))'];
        yield 'nopad collation' => ['LONGTEXT COLLATE utf8mb4_nopad_bin NOT NULL CHECK (json_valid(payload_json))'];
        yield 'other charset' => ['LONGTEXT COLLATE latin1_bin NOT NULL CHECK (json_valid(payload_json))'];
        yield 'nullable' => ['LONGTEXT COLLATE utf8mb4_bin CHECK (json_valid(payload_json))'];
        yield 'literal default' => ["LONGTEXT COLLATE utf8mb4_bin NOT NULL DEFAULT 'null' CHECK (json_valid(payload_json))"];
    }

    /** Quoted SQL NULL is a string even when an alias adds a validity check. */
    public function testJsonAliasDoesNotConflateStringAndNullDefaults(): void
    {
        $this->assertNotSame($this->structure("payload_json JSON DEFAULT 'NULL'", mariaDbJsonAlias: true)->fingerprint(),
            $this->structure('payload_json JSON DEFAULT NULL', mariaDbJsonAlias: true)->fingerprint());
    }

    /** InnoDB and a utf8mb4 catalog alone establish no MariaDB family evidence. */
    public function testJsonAliasRequiresExplicitDestinationEvidence(): void
    {
        $this->assertSame('LONGTEXT[]', $this->structure('payload_json LONGTEXT COLLATE utf8mb4_bin')->columns['payload_json']->type);
        $this->expectException(RestoreFailedException::class);
        $this->structure('payload_json JSON');
    }

    /** Family evidence does not replace the server catalog's character pair proof. */
    public function testJsonAliasRequiresProvenFixedCharacterOptions(): void
    {
        $this->expectException(RestoreFailedException::class);
        TableStructure::read('CREATE TABLE t (payload_json JSON)', 'target',
            new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', mariaDbJsonAlias: true));
    }

    /** @param string $type Unproven JSON syntax or an unknown type */
    #[DataProvider('unsupportedJsonAliases')]
    public function testJsonAliasDoesNotExpandTheSupportedGrammar(string $type): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('payload_json ' . $type, mariaDbJsonAlias: true);
    }

    /** @return iterable<string, array{string}> Alias arguments, overrides, expressions and unsupported attributes */
    public static function unsupportedJsonAliases(): iterable
    {
        yield 'empty arguments' => ['JSON()'];
        yield 'arguments' => ['JSON(1)'];
        yield 'quoted type' => ['`JSON`'];
        yield 'unknown type' => ['UNKNOWN_TYPE'];
        yield 'character set override' => ['JSON CHARACTER SET utf8mb4'];
        yield 'collation override' => ['JSON COLLATE utf8mb4_bin'];
        yield 'unsigned' => ['JSON UNSIGNED'];
        yield 'signed' => ['JSON SIGNED'];
        yield 'zerofill' => ['JSON ZEROFILL'];
        yield 'auto increment' => ['JSON AUTO_INCREMENT'];
        yield 'update expression' => ['JSON ON UPDATE CURRENT_TIMESTAMP'];
        yield 'compression' => ['JSON COMPRESSED'];
        yield 'generated' => ['JSON AS (other)'];
        yield 'parenthesized default' => ["JSON DEFAULT ('{}')"];
        yield 'computed default' => ['JSON DEFAULT JSON_OBJECT()'];
        yield 'timestamp default' => ['JSON DEFAULT CURRENT_TIMESTAMP'];
        yield 'unknown attribute' => ['JSON INVISIBLE'];
        yield 'named check' => ['JSON CONSTRAINT valid CHECK (json_valid(payload_json))'];
        yield 'table check' => ['JSON, CHECK (json_valid(payload_json))'];
        yield 'executable comment' => ['JSON /*!100000 NOT NULL */'];
        yield 'ambiguous escaping' => ["JSON COMMENT 'back\\slash'"];
    }

    /** @param string $expression Unproven expression that must not acquire scalar semantics */
    #[DataProvider('unsupportedDefaults')]
    public function testArbitraryDefaultExpressionsStillRefuse(string $expression): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('value BOOLEAN DEFAULT ' . $expression);
    }

    /** @return iterable<array{string}> Unsupported computations and parenthesized expressions */
    public static function unsupportedDefaults(): iterable
    {
        yield ['(TRUE)'];
        yield ['TRUE + 1'];
        yield ['IF(1, TRUE, FALSE)'];
    }

    /**
     * @param string $source Source column
     * @param string $sourceOptions Source table options
     * @param string $physical Explicit effective column
     * @param string $physicalOptions Explicit effective table options
     */
    #[DataProvider('characterDeclarations')]
    public function testCharacterSetAndCollationInheritanceMatchesPhysicalStructure(
        string $source,
        string $sourceOptions,
        string $physical,
        string $physicalOptions,
    ): void {
        $this->assertSame($this->structure($physical, $physicalOptions)->fingerprint(),
            $this->structure($source, $sourceOptions)->fingerprint());
    }

    /** @return iterable<string, array{string, string, string, string}> Explicit, inherited and COLLATE-only declarations */
    public static function characterDeclarations(): iterable
    {
        yield 'table collate only' => ['label VARCHAR(10)', 'COLLATE=latin1_bin',
            'label VARCHAR(10)', 'DEFAULT CHARSET=latin1 COLLATE=latin1_bin'];
        yield 'column collate only' => ['label VARCHAR(10) COLLATE ascii_bin', '',
            'label VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin', ''];
        yield 'column overrides table' => ['label VARCHAR(10) COLLATE utf8mb4_bin', 'COLLATE=latin1_bin',
            'label VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', 'CHARSET=latin1 COLLATE=latin1_bin'];
        yield 'explicit column charset default' => ['label VARCHAR(10) CHARACTER SET latin1', '',
            'label VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci', ''];
        yield 'explicit table charset default' => ['label VARCHAR(10)', 'CHARSET=ascii',
            'label VARCHAR(10)', 'CHARSET=ascii COLLATE=ascii_general_ci'];
        yield 'schema inheritance' => ['label VARCHAR(10)', '',
            'label VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', ''];
        yield 'table inheritance' => ['label VARCHAR(10)', 'COLLATE=latin1_bin',
            'label VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_bin', 'CHARSET=latin1 COLLATE=latin1_bin'];
        yield 'table option order' => ['label VARCHAR(10)', 'COLLATE=latin1_bin CHARSET=latin1',
            'label VARCHAR(10)', 'CHARSET=latin1 COLLATE=latin1_bin'];
        yield 'default alias' => ['label VARCHAR(10)', 'CHARSET=utf16',
            'label VARCHAR(10)', 'CHARSET=utf16 COLLATE=utf16_uca1400_ai_ci'];
    }

    /**
     * @param string $columns Source columns
     * @param string $options Table character options
     */
    #[DataProvider('unprovenCharacterPairs')]
    public function testUnknownOrIncompatibleCharacterPairsRefuse(string $columns, string $options): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure($columns, $options);
    }

    /** @return iterable<string, array{string, string}> Missing evidence and incompatible explicit pairs */
    public static function unprovenCharacterPairs(): iterable
    {
        yield 'unknown table' => ['label VARCHAR(10)', ' COLLATE=made_up_bin'];
        yield 'unknown column' => ['label VARCHAR(10) COLLATE made_up_bin', ''];
        yield 'incompatible table' => ['label VARCHAR(10)', ' CHARSET=utf8mb4 COLLATE=latin1_bin'];
        yield 'incompatible column' => ['label VARCHAR(10) CHARACTER SET utf8mb4 COLLATE latin1_bin', ''];
    }

    /** A self-column JSON validity constraint survives canonical quoting and remains a material attribute. */
    public function testInlineJsonCheckIsPreservedInTheFingerprint(): void
    {
        $checked = $this->structure('payload_json LONGTEXT NOT NULL CHECK (json_valid(`payload_json`))');
        $this->assertSame($checked->fingerprint(),
            $this->structure('`PAYLOAD_JSON` LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json))')->fingerprint());
        $this->assertNotSame($checked->fingerprint(), $this->structure('payload_json LONGTEXT NOT NULL')->fingerprint());
    }

    /** @param string $check Unsupported, incomplete or ambiguous CHECK grammar */
    #[DataProvider('unsupportedJsonChecks')]
    public function testJsonCheckSupportDoesNotAdmitOtherExpressions(string $check): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('payload_json LONGTEXT ' . $check);
    }

    /** @param string $check An explicit alias CHECK must pass the same strict grammar as physical LONGTEXT */
    #[DataProvider('unsupportedJsonChecks')]
    public function testJsonAliasCannotReplaceAnUnsupportedExplicitCheck(string $check): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('payload_json JSON ' . $check, mariaDbJsonAlias: true);
    }

    /** @return iterable<string, array{string}> Only an unqualified JSON_VALID call on the owning column is modeled */
    public static function unsupportedJsonChecks(): iterable
    {
        yield 'other function' => ['CHECK (json_length(payload_json))'];
        yield 'qualified function' => ['CHECK (target.json_valid(payload_json))'];
        yield 'quoted function' => ['CHECK (`json_valid`(payload_json))'];
        yield 'literal argument' => ["CHECK (json_valid('payload_json'))"];
        yield 'another column' => ['CHECK (json_valid(other))'];
        yield 'multiple arguments' => ['CHECK (json_valid(payload_json, payload_json))'];
        yield 'expression suffix' => ['CHECK (json_valid(payload_json) OR 1)'];
        yield 'nested expression' => ['CHECK (json_valid(COALESCE(payload_json, 1)))'];
        yield 'empty expression' => ['CHECK ()'];
        yield 'missing argument' => ['CHECK (json_valid())'];
        yield 'incomplete group' => ['CHECK (json_valid(payload_json)'];
        yield 'duplicate constraint' => ['CHECK (json_valid(payload_json)) CHECK (json_valid(payload_json))'];
        yield 'enforcement suffix' => ['CHECK (json_valid(payload_json)) NOT ENFORCED'];
    }

    /**
     * The implicit constraint lifecycle on column mutations is outside this narrow proof.
     *
     * @param string $alter Unsupported lifecycle change of a checked column
     */
    #[DataProvider('checkedColumnChanges')]
    public function testAlterCannotGuessTheLifecycleOfAnInlineCheck(string $alter): void
    {
        $structure = $this->structure('payload_json LONGTEXT CHECK (json_valid(payload_json))');
        $this->expectException(RestoreFailedException::class);
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target')[0]);
    }

    /** @param string $alter The synthetic check has the same lifecycle restrictions as an explicit check */
    #[DataProvider('checkedColumnChanges')]
    public function testAlterCannotGuessTheLifecycleOfAnImplicitJsonCheck(string $alter): void
    {
        $structure = $this->structure('payload_json JSON', mariaDbJsonAlias: true);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('constraint lifecycle evidence');
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target')[0]);
    }

    /** @return iterable<string, array{string}> Removing, renaming and replacing a checked column remain conservative */
    public static function checkedColumnChanges(): iterable
    {
        yield 'modify' => ['MODIFY payload_json LONGTEXT'];
        yield 'modify with check' => ['MODIFY payload_json LONGTEXT CHECK (json_valid(payload_json))'];
        yield 'change' => ['CHANGE payload_json renamed LONGTEXT'];
        yield 'rename' => ['RENAME COLUMN payload_json TO renamed'];
        yield 'drop' => ['DROP COLUMN payload_json'];
    }

    /**
     * A foreign key would either follow the renamed column or break, and the proof does not guess which.
     *
     * @param string $alter A rename of the referencing column in one of its accepted forms
     */
    #[DataProvider('foreignKeyColumnRenames')]
    public function testTheColumnOfAForeignKeyCannotBeRenamed(string $alter): void
    {
        $structure = $this->structure('owner_id INT, note INT, CONSTRAINT fk_owner FOREIGN KEY (owner_id) REFERENCES parent (id)');
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('Column rename has ambiguous structural effects');
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target')[0]);
    }

    /** @return iterable<string, array{string}> Every accepted form that renames the referencing column */
    public static function foreignKeyColumnRenames(): iterable
    {
        yield 'rename' => ['RENAME COLUMN owner_id TO owner'];
        yield 'change' => ['CHANGE owner_id owner INT'];
        yield 'rename in another case' => ['RENAME COLUMN OWNER_ID TO owner'];
    }

    /** A table holding a foreign key still renames its other columns (HIL-1926). */
    public function testAnotherColumnOfATableWithAForeignKeyRenames(): void
    {
        $structure = $this->structure('owner_id INT, note INT, CONSTRAINT fk_owner FOREIGN KEY (owner_id) REFERENCES parent (id)');
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t RENAME COLUMN note TO memo', 'target')[0]);
        $this->assertSame(
            $this->structure('owner_id INT, memo INT, CONSTRAINT fk_owner FOREIGN KEY (owner_id) REFERENCES parent (id)')->fingerprint(),
            $structure->fingerprint(),
        );
    }

    /**
     * @param string $source Migration declaration
     * @param string $physical Equivalent SHOW CREATE declaration
     * @param array<string, CheckShape> $checks Expected named CHECKs
     */
    #[DataProvider('namedChecks')]
    public function testNamedCheckMatchesItsPhysicalDeclaration(string $source, string $physical, array $checks): void
    {
        $structure = $this->structure($source);
        $this->assertEquals($checks, $structure->checks);
        $this->assertSame($this->structure($physical)->fingerprint(), $structure->fingerprint());
    }

    /** @return iterable<string, array> Both real forms, then identifier quoting, name case, spacing, position and range */
    public static function namedChecks(): iterable
    {
        $bounds = ['ck_status_silence_period_bounds' => new CheckShape('>',
            [[CheckShape::COLUMN, 'ended_at'], [CheckShape::COLUMN, 'started_at']], true)];
        $oneRow = ['chk_i18n_reflow_one_row' => new CheckShape('=', [[CheckShape::COLUMN, 'id'], [CheckShape::LITERAL, '1']], true)];
        $physicalBounds = '`started_at` bigint(20) NOT NULL, `ended_at` bigint(20) NOT NULL,'
            . ' CONSTRAINT `ck_status_silence_period_bounds` CHECK (`ended_at` > `started_at`)';
        yield 'chat silence period' => [self::SILENCE_PERIOD_SOURCE, self::SILENCE_PERIOD_PHYSICAL, $bounds];
        yield 'i18n reflow' => [self::I18N_REFLOW_SOURCE, self::I18N_REFLOW_PHYSICAL, $oneRow];
        yield 'bare identifiers' => ['started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL,'
            . ' CONSTRAINT ck_status_silence_period_bounds CHECK (ended_at > started_at)', $physicalBounds, $bounds];
        yield 'name case and spacing' => ['id TINYINT UNSIGNED NOT NULL, CONSTRAINT CHK_I18N_REFLOW_ONE_ROW CHECK(`ID`=1)',
            '`id` tinyint(3) unsigned NOT NULL, CONSTRAINT `chk_i18n_reflow_one_row` CHECK (`id` = 1)', $oneRow];
        yield 'declared before its columns' => ['CONSTRAINT `ck_status_silence_period_bounds` CHECK (`ended_at` > `started_at`),'
            . ' started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL', $physicalBounds, $bounds];
        yield 'literal beyond the PHP integer range' => ['id BIGINT UNSIGNED NOT NULL, CONSTRAINT ck_top CHECK (id = 18446744073709551615)',
            '`id` bigint(20) unsigned NOT NULL, CONSTRAINT `ck_top` CHECK (`id` = 18446744073709551615)',
            ['ck_top' => new CheckShape('=', [[CheckShape::COLUMN, 'id'], [CheckShape::LITERAL, '18446744073709551615']], true)]];
        yield 'quoted column spelled like a keyword' => ['a BIGINT NOT NULL, `null` BIGINT NOT NULL, CONSTRAINT ck CHECK (a > `null`)',
            '`a` bigint(20) NOT NULL, `null` bigint(20) NOT NULL, CONSTRAINT `ck` CHECK (`a` > `null`)',
            ['ck' => new CheckShape('>', [[CheckShape::COLUMN, 'a'], [CheckShape::COLUMN, 'null']], true)]];
    }

    /**
     * @param string $columns Declaration with a reference CHECK
     * @param string $different The same table with a missing, additional or changed CHECK
     */
    #[DataProvider('differentNamedChecks')]
    public function testNamedCheckDifferencesChangeTheFingerprint(string $columns, string $different): void
    {
        $this->assertNotSame($this->structure($columns)->fingerprint(), $this->structure($different)->fingerprint());
    }

    /** @return iterable<string, array{string, string}> Presence, name, operator, operand order and kind, column and literal */
    public static function differentNamedChecks(): iterable
    {
        $periods = 'started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, other BIGINT NOT NULL';
        $bounds = $periods . ', CONSTRAINT ck_bounds CHECK (ended_at > started_at)';
        yield 'comparison missing' => [$bounds, $periods];
        yield 'comparison added' => [$bounds, $bounds . ', CONSTRAINT ck_other CHECK (other = 0)'];
        yield 'comparison renamed' => [$bounds, $periods . ', CONSTRAINT ck_renamed CHECK (ended_at > started_at)'];
        yield 'comparison operand order' => [$bounds, $periods . ', CONSTRAINT ck_bounds CHECK (started_at > ended_at)'];
        yield 'comparison column' => [$bounds, $periods . ', CONSTRAINT ck_bounds CHECK (ended_at > other)'];
        yield 'comparison operator and operand kind' => [$bounds, $periods . ', CONSTRAINT ck_bounds CHECK (ended_at = 0)'];
        $rows = 'id TINYINT UNSIGNED NOT NULL, other TINYINT UNSIGNED NOT NULL';
        $oneRow = $rows . ', CONSTRAINT chk_one_row CHECK (id = 1)';
        yield 'equality missing' => [$oneRow, $rows];
        yield 'equality added' => [$oneRow, $oneRow . ', CONSTRAINT chk_other CHECK (other = 1)'];
        yield 'equality renamed' => [$oneRow, $rows . ', CONSTRAINT chk_renamed CHECK (id = 1)'];
        yield 'equality literal' => [$oneRow, $rows . ', CONSTRAINT chk_one_row CHECK (id = 2)'];
        yield 'equality column' => [$oneRow, $rows . ', CONSTRAINT chk_one_row CHECK (other = 1)'];
        yield 'equality operator and operand kind' => [$oneRow, $rows . ', CONSTRAINT chk_one_row CHECK (id > other)'];
        $wide = 'id BIGINT UNSIGNED NOT NULL, CONSTRAINT ck_top CHECK (id = ';
        yield 'literal beyond the PHP integer range' => [$wide . '18446744073709551615)', $wide . '18446744073709551614)'];
        // MariaDB prints CHECK in creation order, and a swap is an unproven equivalence: a false refusal beats a false permit
        $pair = 'a BIGINT NOT NULL, b BIGINT NOT NULL, ';
        yield 'checks in another order' => [
            $pair . 'CONSTRAINT ck_a CHECK (a > b), CONSTRAINT ck_b CHECK (b = 1)',
            $pair . 'CONSTRAINT ck_b CHECK (b = 1), CONSTRAINT ck_a CHECK (a > b)',
        ];
    }

    /** @param string $check Unsupported named CHECK beside integer, text and decimal columns */
    #[DataProvider('unsupportedNamedChecks')]
    public function testNamedCheckSupportDoesNotAdmitOtherForms(string $check): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('a BIGINT NOT NULL, b INT UNSIGNED NOT NULL, label VARCHAR(10), amount DECIMAL(10, 0), ' . $check);
    }

    /** @return iterable<string, array{string}> Only the two named forms on present integer columns, without a status suffix */
    public static function unsupportedNamedChecks(): iterable
    {
        yield 'unnamed' => ['CHECK (a > b)'];
        yield 'constraint without a name' => ['CONSTRAINT CHECK (a > b)'];
        yield 'greater or equal' => ['CONSTRAINT ck CHECK (a >= b)'];
        yield 'less' => ['CONSTRAINT ck CHECK (a < b)'];
        yield 'not equal' => ['CONSTRAINT ck CHECK (a <> b)'];
        yield 'bang not equal' => ['CONSTRAINT ck CHECK (a != b)'];
        yield 'null-safe equal' => ['CONSTRAINT ck CHECK (a <=> 1)'];
        yield 'equal columns' => ['CONSTRAINT ck CHECK (a = b)'];
        yield 'greater than a literal' => ['CONSTRAINT ck CHECK (a > 1)'];
        yield 'reversed equality' => ['CONSTRAINT ck CHECK (1 = a)'];
        yield 'leading zero' => ['CONSTRAINT ck CHECK (a = 01)'];
        yield 'fraction' => ['CONSTRAINT ck CHECK (a = 1.0)'];
        yield 'exponent' => ['CONSTRAINT ck CHECK (a = 1e0)'];
        yield 'exponent as a column' => ['CONSTRAINT ck CHECK (a > 1e0)'];
        yield 'hexadecimal' => ['CONSTRAINT ck CHECK (a = 0x1)'];
        yield 'negative' => ['CONSTRAINT ck CHECK (a = -1)'];
        yield 'explicit sign' => ['CONSTRAINT ck CHECK (a = +1)'];
        yield 'string literal' => ["CONSTRAINT ck CHECK (a = '1')"];
        yield 'boolean literal' => ['CONSTRAINT ck CHECK (a = TRUE)'];
        yield 'null literal' => ['CONSTRAINT ck CHECK (a = NULL)'];
        yield 'bare null beside a column named null' => ['`null` BIGINT NOT NULL, CONSTRAINT ck CHECK (a > NULL)'];
        yield 'bare null on the left' => ['`null` BIGINT NOT NULL, CONSTRAINT ck CHECK (NULL > a)'];
        yield 'bare lower-case null' => ['`null` BIGINT NOT NULL, CONSTRAINT ck CHECK (a > null)'];
        yield 'bare true beside a column named true' => ['`true` BIGINT NOT NULL, CONSTRAINT ck CHECK (a > TRUE)'];
        yield 'bare false on the left' => ['`false` BIGINT NOT NULL, CONSTRAINT ck CHECK (FALSE > a)'];
        yield 'bare current user beside a column' => ['`current_user` BIGINT NOT NULL, CONSTRAINT ck CHECK (a > CURRENT_USER)'];
        yield 'bare current timestamp on the left' => ['`current_timestamp` BIGINT NOT NULL, CONSTRAINT ck CHECK (CURRENT_TIMESTAMP > a)'];
        yield 'string operand' => ['CONSTRAINT ck CHECK ("a" > b)'];
        yield 'outer parentheses' => ['CONSTRAINT ck CHECK ((a > b))'];
        yield 'operand parentheses' => ['CONSTRAINT ck CHECK ((a) > b)'];
        yield 'qualified column' => ['CONSTRAINT ck CHECK (t.a > b)'];
        yield 'function operand' => ['CONSTRAINT ck CHECK (abs(a) > b)'];
        yield 'compound' => ['CONSTRAINT ck CHECK (a > b AND a = 1)'];
        yield 'empty' => ['CONSTRAINT ck CHECK ()'];
        yield 'missing operand' => ['CONSTRAINT ck CHECK (a >)'];
        yield 'missing operator' => ['CONSTRAINT ck CHECK (a b)'];
        yield 'missing parentheses' => ['CONSTRAINT ck CHECK a > b'];
        yield 'absent column' => ['CONSTRAINT ck CHECK (a > missing)'];
        yield 'text column' => ['CONSTRAINT ck CHECK (a > label)'];
        yield 'decimal column' => ['CONSTRAINT ck CHECK (amount = 1)'];
        yield 'duplicate name' => ['CONSTRAINT ck CHECK (a > b), CONSTRAINT ck CHECK (a = 1)'];
        yield 'duplicate name in another case' => ['CONSTRAINT ck CHECK (a > b), CONSTRAINT `CK` CHECK (a = 1)'];
        yield 'name shared with a foreign key' => ['CONSTRAINT ck CHECK (a > b), CONSTRAINT ck FOREIGN KEY (a) REFERENCES parent (id)'];
        yield 'enforced suffix' => ['CONSTRAINT ck CHECK (a > b) ENFORCED'];
        yield 'not enforced suffix' => ['CONSTRAINT ck CHECK (a > b) NOT ENFORCED'];
        yield 'executable comment' => ['CONSTRAINT ck CHECK (a > b /*!100000 AND a = 1 */)'];
    }

    /**
     * Only a direct CREATE establishes a named CHECK, and the columns it reads cannot change under it.
     *
     * @param string $columns Table with one of the modeled forms
     * @param string $alter Clause the ALTER reader admits
     */
    #[DataProvider('namedCheckLifecycleChanges')]
    public function testAlterCannotGuessTheLifecycleOfANamedCheck(string $columns, string $alter): void
    {
        $structure = $this->structure($columns);
        $operation = MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target')[0];
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('constraint lifecycle evidence');
        $structure->alter($operation);
    }

    /** @return iterable<string, array{string, string}> Adding a CHECK and changing either operand column of both forms */
    public static function namedCheckLifecycleChanges(): iterable
    {
        $bounds = 'started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, note BIGINT, CONSTRAINT ck_bounds CHECK (ended_at > started_at)';
        $oneRow = 'id TINYINT UNSIGNED NOT NULL, note BIGINT, CONSTRAINT chk_one_row CHECK (id = 1)';
        yield 'add comparison' => [$bounds, 'ADD CONSTRAINT ck_note CHECK (note > started_at)'];
        yield 'add equality' => [$oneRow, 'ADD CONSTRAINT ck_note CHECK (note = 1)'];
        yield 'add to a table without one' => ['note BIGINT', 'ADD CONSTRAINT ck_note CHECK (note = 1)'];
        yield 'add unnamed' => [$oneRow, 'ADD CHECK (note = 1)'];
        yield 'modify left operand' => [$bounds, 'MODIFY ended_at BIGINT NOT NULL'];
        yield 'modify right operand' => [$bounds, 'MODIFY started_at BIGINT NOT NULL'];
        yield 'change' => [$bounds, 'CHANGE ended_at finished_at BIGINT NOT NULL'];
        yield 'rename' => [$bounds, 'RENAME COLUMN started_at TO began_at'];
        yield 'rename in another case' => [$bounds, 'RENAME COLUMN ENDED_AT TO finished_at'];
        yield 'drop' => [$bounds, 'DROP COLUMN ended_at'];
        yield 'modify equality column' => [$oneRow, 'MODIFY id TINYINT UNSIGNED NOT NULL'];
        yield 'change equality column' => [$oneRow, 'CHANGE id row_id TINYINT UNSIGNED NOT NULL'];
        yield 'rename equality column' => [$oneRow, 'RENAME COLUMN id TO row_id'];
        yield 'drop equality column' => [$oneRow, 'DROP COLUMN id'];
    }

    /**
     * The ALTER reader itself leaves these opaque, and the planner refuses an opaque UP before any structure is replayed.
     *
     * @param string $alter Removing, replacing or toggling a named CHECK
     */
    #[DataProvider('namedCheckRemovals')]
    public function testAlterCannotRemoveReplaceOrToggleANamedCheck(string $alter): void
    {
        $operations = MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target');
        $this->assertSame([MigrationOperationKind::UNKNOWN], array_column($operations, 'kind'));
    }

    /** @return iterable<string, array{string}> Every spelling of a CHECK lifecycle step outside a direct CREATE */
    public static function namedCheckRemovals(): iterable
    {
        yield 'drop constraint' => ['DROP CONSTRAINT ck_bounds'];
        yield 'drop check' => ['DROP CHECK ck_bounds'];
        yield 'drop constraint if exists' => ['DROP CONSTRAINT IF EXISTS ck_bounds'];
        yield 'replace' => ['DROP CONSTRAINT ck_bounds, ADD CONSTRAINT ck_bounds CHECK (ended_at > started_at)'];
        yield 'toggle check' => ['ALTER CHECK ck_bounds NOT ENFORCED'];
        yield 'toggle constraint' => ['ALTER CONSTRAINT ck_bounds ENFORCED'];
    }

    /**
     * A table holding a named CHECK still changes the columns the CHECK does not read, and keeps the CHECK.
     *
     * @param string $alter ALTER of another column
     * @param string $columns The same result declared directly
     */
    #[DataProvider('uncheckedColumnChanges')]
    public function testOtherColumnsOfATableWithANamedCheckChange(string $alter, string $columns): void
    {
        $check = ', CONSTRAINT ck_bounds CHECK (ended_at > started_at)';
        $structure = $this->structure('started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, note INT' . $check);
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t ' . $alter, 'target')[0]);
        $this->assertSame($this->structure($columns . $check)->fingerprint(), $structure->fingerprint());
    }

    /** @return iterable<string, array{string, string}> Rename, modify, drop and add beside the checked columns */
    public static function uncheckedColumnChanges(): iterable
    {
        yield 'rename' => ['RENAME COLUMN note TO memo', 'started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, memo INT'];
        yield 'modify' => ['MODIFY note BIGINT NOT NULL', 'started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, note BIGINT NOT NULL'];
        yield 'drop' => ['DROP COLUMN note', 'started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL'];
        yield 'add first' => ['ADD COLUMN extra INT FIRST', 'extra INT, started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL, note INT'];
    }

    /** SHOW CREATE adds an engine to each partition without changing the declared structure. */
    public function testRangeColumnsPartitionsMatchThePhysicalEngineForm(): void
    {
        $columns = 'created_at BIGINT NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (id, created_at)';
        $source = $this->structure($columns, 'PARTITION BY RANGE COLUMNS (`created_at`) ('
            . 'PARTITION p202601 VALUES LESS THAN (1769904000000), PARTITION p_future VALUES LESS THAN (MAXVALUE))');
        $physical = $this->structure($columns, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            . ' ROW_FORMAT=Dynamic PARTITION BY RANGE COLUMNS (CREATED_AT) ('
            . 'PARTITION `P202601` VALUES LESS THAN (1769904000000) ENGINE = InnoDB,'
            . ' PARTITION `P_FUTURE` VALUES LESS THAN (MAXVALUE) ENGINE InnoDB)');
        $this->assertSame($source->fingerprint(), $physical->fingerprint());
        $this->assertSame('utf8mb4', $source->options['charset']);
        $this->assertSame('dynamic', $source->options['row_format']);
        $this->assertArrayHasKey('partition', $source->options);
        $this->assertSame($this->structure($columns, "PARTITION BY RANGE COLUMNS (created_at) ("
            . "PARTITION p202601 VALUES LESS THAN ('2026-02-01'), PARTITION p_future VALUES LESS THAN (MAXVALUE))")->fingerprint(),
            $this->structure($columns, "PARTITION BY RANGE COLUMNS (`CREATED_AT`) ("
                . "PARTITION p202601 VALUES LESS THAN ('2026-02-01') ENGINE=InnoDB,"
                . ' PARTITION p_future VALUES LESS THAN (MAXVALUE) ENGINE=InnoDB)')->fingerprint());
    }

    /** @param string $partitions A material difference in the ordered partition definition */
    #[DataProvider('differentPartitions')]
    public function testPartitionsRemainPartOfTheStructuralFingerprint(string $partitions): void
    {
        $columns = 'created_at BIGINT NOT NULL, other BIGINT NOT NULL';
        $source = $this->structure($columns, 'PARTITION BY RANGE COLUMNS (created_at) ('
            . 'PARTITION p202601 VALUES LESS THAN (1769904000000), PARTITION p_future VALUES LESS THAN (MAXVALUE))');
        $this->assertNotSame($source->fingerprint(), $this->structure($columns, $partitions)->fingerprint());
    }

    /** @return iterable<string, array{string}> Column, name, bound, membership and order are material */
    public static function differentPartitions(): iterable
    {
        yield 'column' => ['PARTITION BY RANGE COLUMNS (other) ('
            . 'PARTITION p202601 VALUES LESS THAN (1769904000000), PARTITION p_future VALUES LESS THAN (MAXVALUE))'];
        yield 'name' => ['PARTITION BY RANGE COLUMNS (created_at) ('
            . 'PARTITION renamed VALUES LESS THAN (1769904000000), PARTITION p_future VALUES LESS THAN (MAXVALUE))'];
        yield 'bound' => ['PARTITION BY RANGE COLUMNS (created_at) ('
            . 'PARTITION p202601 VALUES LESS THAN (1769904000001), PARTITION p_future VALUES LESS THAN (MAXVALUE))'];
        yield 'bound kind' => ["PARTITION BY RANGE COLUMNS (created_at) ("
            . "PARTITION p202601 VALUES LESS THAN ('1769904000000'), PARTITION p_future VALUES LESS THAN (MAXVALUE))"];
        yield 'membership' => ['PARTITION BY RANGE COLUMNS (created_at) (PARTITION p_future VALUES LESS THAN (MAXVALUE))'];
        yield 'order' => ['PARTITION BY RANGE COLUMNS (created_at) ('
            . 'PARTITION p_future VALUES LESS THAN (MAXVALUE), PARTITION p202601 VALUES LESS THAN (1769904000000))'];
        yield 'no partitions' => [''];
    }

    /** @param string $partitions Unsupported grammar or a partition column absent from the table */
    #[DataProvider('unsupportedPartitions')]
    public function testUnsupportedPartitionsStillRefuseStructuralProof(string $partitions): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->structure('created_at BIGINT NOT NULL', $partitions);
    }

    /** @return iterable<string, array{string}> The partition grammar remains RANGE COLUMNS with a matching engine */
    public static function unsupportedPartitions(): iterable
    {
        yield 'hash' => ['PARTITION BY HASH (created_at) PARTITIONS 2'];
        yield 'key' => ['PARTITION BY KEY (created_at) PARTITIONS 2'];
        yield 'list' => ['PARTITION BY LIST COLUMNS (created_at) (PARTITION p VALUES IN (1))'];
        yield 'subpartition' => ['PARTITION BY RANGE COLUMNS (created_at) SUBPARTITION BY HASH (created_at) ('
            . 'PARTITION p VALUES LESS THAN (MAXVALUE))'];
        yield 'option' => ["PARTITION BY RANGE COLUMNS (created_at) (PARTITION p VALUES LESS THAN (MAXVALUE) COMMENT 'x')"];
        yield 'engine' => ['PARTITION BY RANGE COLUMNS (created_at) (PARTITION p VALUES LESS THAN (MAXVALUE) ENGINE=MyISAM)'];
        yield 'absent column' => ['PARTITION BY RANGE COLUMNS (absent) (PARTITION p VALUES LESS THAN (MAXVALUE))'];
        yield 'suffix' => ['PARTITION BY RANGE COLUMNS (created_at) (PARTITION p VALUES LESS THAN (MAXVALUE)) ROW_FORMAT=DYNAMIC'];
        yield 'expression' => ['PARTITION BY RANGE COLUMNS (created_at) (PARTITION p VALUES LESS THAN (1 + 1))'];
        yield 'quoted maxvalue' => ['PARTITION BY RANGE COLUMNS (created_at) (PARTITION p VALUES LESS THAN (`MAXVALUE`))'];
    }

    /**
     * @param string $columns Column list
     * @param string $options Table options
     * @param bool $mariaDbJsonAlias Explicit invocation evidence; absent for ordinary callers
     * @return TableStructure Parsed source or physical declaration
     */
    private function structure(string $columns, string $options = '', bool $mariaDbJsonAlias = false): TableStructure
    {
        $defaults = new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', [
            'utf8mb4' => 'utf8mb4_general_ci', 'latin1' => 'latin1_swedish_ci', 'ascii' => 'ascii_general_ci',
            'utf16' => 'utf16_uca1400_ai_ci',
        ], charsetByCollation: ['latin1_bin' => 'latin1', 'ascii_bin' => 'ascii', 'utf8mb4_bin' => 'utf8mb4',
            'utf8mb4_nopad_bin' => 'utf8mb4'], mariaDbJsonAlias: $mariaDbJsonAlias);
        return TableStructure::read('CREATE TABLE t (' . $columns . ') ' . $options, 'target', $defaults);
    }
}
