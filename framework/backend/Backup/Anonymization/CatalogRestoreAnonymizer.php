<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreAnonymizer;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * CatalogRestoreAnonymizer - the anonymizer a catalog-configured installation gets.
 *
 * Holds the two things one restore run needs and nothing else: the merged registry
 * ({@see PiiRegistry}) and the salt its hashes take. The salt is minted here, per run,
 * from the secure source and is never written down. That is the whole correlation story:
 * one salt across every table of the run keeps a value equal to itself, so a join by
 * hashed email still works inside the restored copy, while two restores of the same
 * archive produce two sets of hashes nobody can line up against each other.
 *
 * The live schemas arrive through {@see validateTargetSchema()} and are kept for the pass
 * rather than read twice: the statements are built against the very schema the gate just
 * judged, so there is no window in which the pass could be writing by one description of
 * a column while the refusal was decided by another.
 */
final class CatalogRestoreAnonymizer implements RestoreAnonymizer
{
    /**
     * Salt length. Wider than the 16 bytes a salt strictly needs, because the value is
     * per run and costs nothing, while a salt that turns out to be short costs a rerun of
     * the whole restore to fix.
     */
    private const int SALT_BYTES = 32;

    /** @var array<int, array<string, LiveTableSchema>> Live tables per connection, as validated */
    private array $schemasByConnection = [];

    /**
     * @param PiiRegistry $registry Merged framework and project registry this run acts on
     * @param AnonymizationSqlBuilder $sql Builder over this run's salt
     */
    public function __construct(
        private readonly PiiRegistry $registry,
        private readonly AnonymizationSqlBuilder $sql,
    ) {
    }

    /**
     * Builds the anonymizer this installation's catalog describes.
     *
     * @return ?self Anonymizer over the declared registry, or null when nothing is declared
     * @throws AnonymizationConfigException When a declaration is not a registry
     * @throws RandomException When the platform's secure random source refuses to mint a salt
     */
    public static function fromCatalog(): ?self
    {
        $registry = PiiRegistry::fromCatalog();
        if ($registry->isEmpty()) {
            return null;
        }

        return new self($registry, new AnonymizationSqlBuilder(RandomHelper::secureHex(self::SALT_BYTES)));
    }

    /**
     * Checks that the registry classifies every table the archive carries.
     *
     * @param array<int, list<string>> $tablesByConnection Archive table names per connection index
     * @throws AnonymizationConfigException When the registry leaves a table of the archive
     *     unclassified
     */
    public function validateArchive(array $tablesByConnection): void
    {
        AnonymizationCoverageValidator::validate($this->registry, $tablesByConnection);
    }

    /**
     * Checks the registry against one connection's live schema and keeps it for the pass.
     *
     * @param int $index Connection index whose schema is judged
     * @param string $database Database name the connection imported into, for the refusal
     * @throws AnonymizationConfigException When the schema cannot be read, or a declared column
     *     is absent from it or cannot carry its strategy
     */
    public function validateTargetSchema(int $index, string $database): void
    {
        try {
            $schemas = LiveSchemaReader::read($index);
            AnonymizationCompatibilityValidator::validate(
                $this->registry,
                $index,
                $schemas,
                self::maxPrimaryKey(...),
            );
        } catch (AnonymizationConfigException $refusal) {
            throw new AnonymizationConfigException(
                self::holdsPii($index, $database) . $refusal->getMessage(),
                0,
                $refusal,
            );
        } catch (DatabaseException $failure) {
            throw new AnonymizationConfigException(
                self::holdsPii($index, $database) . 'its schema cannot be read: ' . $failure->getMessage(),
                0,
                $failure,
            );
        }

        $this->schemasByConnection[$index] = $schemas;
    }

    /**
     * Rewrites the freshly imported data of one connection.
     *
     * One transaction per connection: a half-anonymized database is worse than an
     * un-anonymized one, because it looks like the pass ran. Either every table of the
     * connection is rewritten or none is, and the restore fails loudly - the operator is
     * told the database holds personal data, and nothing is silently deleted to hide it
     * (returning the system to a consistent state after a failed restore is HIL-436).
     *
     * @param int $index Connection index the pass runs over
     * @param string $database Database name the connection imported into
     * @throws RestoreFailedException When a statement of the pass fails
     * @throws DatabaseException When the connection the pass rewrites over cannot be reached
     */
    public function anonymizeConnection(int $index, string $database): void
    {
        $statements = $this->statementsFor($index);
        if ($statements === []) {
            return;
        }

        Database::useConnection($index);
        try {
            Database::transactionStart();
            foreach ($statements as $statement) {
                // No reconnect: a reconnect would silently drop this transaction, after which
                // the remaining statements auto-commit and the commit below succeeds over a
                // half-anonymized database. A lost connection has to end the restore.
                Database::sql($statement, tryReconnect: false);
            }
            Database::transactionCommit();
        } catch (DatabaseException $failure) {
            self::rollBack();

            throw new RestoreFailedException(
                self::holdsPii($index, $database) . $failure->getMessage(),
                0,
                $failure,
            );
        }
    }

    /**
     * Builds the statements one connection's pass runs, in the registry's declaration order.
     *
     * The registry leads rather than the schema: it is the shorter list of the two, it is
     * the one a person wrote and can read the pass back against, and a table it declares
     * that this installation does not carry is a row that simply has nothing to do here -
     * the archive was already judged for coverage before the import.
     *
     * @param int $index Connection index
     * @return list<string> Statements to run
     * @throws AnonymizationConfigException When a strategy cannot be expressed; the gate over
     *     the live schema refuses these before a restore reaches this point
     */
    private function statementsFor(int $index): array
    {
        $schemas = $this->schemasByConnection[$index] ?? [];
        $statements = [];
        foreach ($this->registry->declaredTables($index) as $table) {
            $schema = $schemas[$table] ?? null;
            if ($schema === null) {
                continue;
            }
            if ($this->registry->isPurged($index, $table)) {
                $statements[] = $this->sql->purgeStatement($table);

                continue;
            }

            $update = $this->sql->updateStatement(
                $schema,
                $this->registry->strategiesFor($index, $table) ?? [],
            );
            if ($update !== null) {
                $statements[] = $update;
            }
        }

        return $statements;
    }

    /**
     * Reads the largest primary key one table currently holds.
     *
     * The gate measures a `fake-*` value against this rather than against the width of the
     * key's type: the widest `int` renders to ten characters, and refusing a `varchar(32)`
     * column over a table whose ids are four digits long would be a refusal about arithmetic
     * rather than about data. The rows are already imported when this runs, so the number is
     * the real one and not a forecast.
     *
     * @param string $table Table to read
     * @param string $column Its single primary key column
     * @return int Largest key value, or 0 when the table holds no rows
     * @throws DatabaseException When the query fails
     */
    private static function maxPrimaryKey(string $table, string $column): int
    {
        Database::sql(AnonymizationSqlBuilder::maxPrimaryKeyStatement($table, $column));

        return (int)Database::field(AnonymizationSqlBuilder::MAX_PRIMARY_KEY_ALIAS);
    }

    /**
     * Opens a refusal that has to say the database already holds personal data.
     *
     * Both refusals raised past the import say it, and they say it identically on purpose:
     * an operator reading either one is in the same situation and needs the same words -
     * the restore happened, and what came out of it is production data.
     *
     * @param int $index Connection index the refusal is about
     * @param string $database Database name the connection imported into
     * @return string Opening of the message, ending where the cause continues it
     */
    private static function holdsPii(int $index, string $database): string
    {
        return "Database [{$database}] on connection {$index} is restored but NOT anonymized - "
            . 'it holds PII: ';
    }

    /**
     * Rolls the pass back, best effort.
     *
     * A rollback that itself fails changes nothing an operator can act on: the restore is
     * already failing, and the message it fails with already says the database is to be
     * treated as holding personal data.
     */
    private static function rollBack(): void
    {
        try {
            Database::transactionRollback();
        } catch (DatabaseException) {
            // The pass is already lost; the caller's refusal carries the outcome.
        }
    }
}
