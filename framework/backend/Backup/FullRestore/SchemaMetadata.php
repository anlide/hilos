<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Raw catalog rows are intentionally retained at this infrastructure boundary. */
final readonly class SchemaMetadata
{
    public const string CHECK_CONSTRAINT_CHECKS = 'check_constraint_checks';
    private const string CHECKS_ENFORCED = 'ON';
    private const string CHECKS_DISABLED = 'OFF';
    private const array INCOMPLETE_DECLARATION_MODES = [
        'NO_FIELD_OPTIONS', 'NO_TABLE_OPTIONS', 'NO_KEY_OPTIONS', 'ANSI', 'DB2', 'POSTGRESQL', 'MSSQL', 'MAXDB', 'ORACLE',
    ];

    /**
     * @param string $server Physical server identity without the schema component
     * @param array<string, LiveObject> $objects Complete visible destination object map
     * @param list<array<string, mixed>> $defaults Schema charset/collation and execution defaults
     * @param list<array<string, mixed>> $columns Complete column properties in storage order
     * @param list<array<string, mixed>> $indexes Complete physical index properties
     * @param StructureDefaults $structureDefaults Effective CREATE defaults for semantic replay
     * @param list<string> $grants Direct grants proving that the destination object set is visible
     * @param bool $completeTriggerVisibility Whether this account sees every trigger in the selected schema
     * @param list<array<string, mixed>> $triggers Trigger names and owners read through this schema's actual connection
     * @param ?string $checkConstraintChecks This session's reported check_constraint_checks, or null when the server reports none
     */
    public function __construct(
        public string $server,
        public array $objects,
        public array $defaults,
        public array $columns,
        public array $indexes,
        public StructureDefaults $structureDefaults,
        public array $grants = [],
        public bool $completeTriggerVisibility = false,
        public array $triggers = [],
        public ?string $checkConstraintChecks = null,
    ) {
    }

    /**
     * The planner uses exact schema/table keys and SHOW CREATE for lineage. Folding modes and
     * declarations with suppressed attributes cannot establish that proof, even when defaults match.
     * The snapshot retains these session defaults so its final recheck also detects a mode change.
     *
     * @throws RestoreFailedException When physical names or complete declarations cannot be proven
     */
    public function assertSupportedProof(): void
    {
        if (count($this->defaults) !== 1 || !in_array($this->defaults[0]['lower_case_table_names'] ?? null, [0, '0'], true)) {
            throw new RestoreFailedException('Full restore physical identity requires proven lower_case_table_names=0;'
                . ' case-insensitive or unknown schema/table addressing is unsupported');
        }
        $suppressed = array_intersect(self::INCOMPLETE_DECLARATION_MODES,
            array_map('trim', explode(',', strtoupper($this->structureDefaults->sqlMode))));
        if ($suppressed !== []) {
            throw new RestoreFailedException('SHOW CREATE omits physical properties under SQL_MODE ' . implode(', ', $suppressed)
                . '; full restore structure proof is unavailable');
        }
    }

    /**
     * A modeled named CHECK proves the rows of a table only when the session that executes its UP enforces it.
     * MariaDB reports the variable as ON or OFF; no such variable, or any other report, leaves enforcement unknown.
     * The restore reads this session state and never switches it.
     *
     * @param string $reliance What relies on the named CHECK, as the subject of the refusal
     * @throws RestoreFailedException When this session's CHECK enforcement is not proven ON
     */
    public function assertEnforcesChecks(string $reliance): void
    {
        if ($this->checkConstraintChecks === self::CHECKS_ENFORCED) {
            return;
        }
        throw new RestoreFailedException($reliance . ' requires ' . self::CHECK_CONSTRAINT_CHECKS . '=' . self::CHECKS_ENFORCED
            . ' in the session that executes UP; in that session it is '
            . ($this->checkConstraintChecks === self::CHECKS_DISABLED ? self::CHECKS_DISABLED : 'unknown'));
    }
}
