<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\ColumnShape;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FullRestoreTableStructureTest extends TestCase
{
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
