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
 * The archive schemas arrive through {@see validateArchive()} and are kept for the pass
 * rather than read twice: the pass runs against the tables the archive actually carries,
 * and re-reading the dump files after the import would ask the same question of files
 * that are about to be swept.
 */
final class CatalogRestoreAnonymizer implements RestoreAnonymizer
{
    /**
     * Salt length. Wider than the 16 bytes a salt strictly needs, because the value is
     * per run and costs nothing, while a salt that turns out to be short costs a rerun of
     * the whole restore to fix.
     */
    private const int SALT_BYTES = 32;

    /** @var array<int, list<ArchiveTableSchema>> Archive tables per connection, as validated */
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
     * Checks the registry against the archive and keeps the schemas for the pass.
     *
     * @param array<int, list<ArchiveTableSchema>> $schemasByConnection Archive tables per connection index
     * @throws AnonymizationConfigException When the registry does not cover or does not fit the archive
     */
    public function validateArchive(array $schemasByConnection): void
    {
        AnonymizationValidator::validate($this->registry, $schemasByConnection);
        $this->schemasByConnection = $schemasByConnection;
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
                "Database [{$database}] on connection {$index} is restored but NOT anonymized - "
                . 'it holds PII: ' . $failure->getMessage(),
                0,
                $failure,
            );
        }
    }

    /**
     * Builds the statements one connection's pass runs, in archive order.
     *
     * @param int $index Connection index
     * @return list<string> Statements to run
     * @throws AnonymizationConfigException When a strategy cannot be expressed; the preflight
     *     gate refuses these before a restore reaches this point
     */
    private function statementsFor(int $index): array
    {
        $statements = [];
        foreach ($this->schemasByConnection[$index] ?? [] as $schema) {
            if ($this->registry->isPurged($index, $schema->table)) {
                $statements[] = $this->sql->purgeStatement($schema->table);

                continue;
            }

            $update = $this->sql->updateStatement(
                $schema,
                $this->registry->strategiesFor($index, $schema->table) ?? [],
            );
            if ($update !== null) {
                $statements[] = $update;
            }
        }

        return $statements;
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
