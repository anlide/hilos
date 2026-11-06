<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
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
     * @return TableStructure Parsed source or physical declaration
     */
    private function structure(string $columns, string $options = ''): TableStructure
    {
        $defaults = new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', [
            'utf8mb4' => 'utf8mb4_general_ci', 'latin1' => 'latin1_swedish_ci', 'ascii' => 'ascii_general_ci',
            'utf16' => 'utf16_uca1400_ai_ci',
        ], charsetByCollation: ['latin1_bin' => 'latin1', 'ascii_bin' => 'ascii', 'utf8mb4_bin' => 'utf8mb4']);
        return TableStructure::read('CREATE TABLE t (' . $columns . ') ' . $options, 'target', $defaults);
    }
}
