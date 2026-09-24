<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodReadiness;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * Framework sign-in methods table: one row per method the project wired (HIL-427).
 *
 * A self-snapshot table over the project's method directory and the one setting that
 * switches methods off, not a DB source. Each row says whether the method is on and whether
 * the installation can serve it at all ({@see AuthMethodReadiness}) - the same answer the
 * sign-in surfaces and the rule on the list of switched-off methods get (HIL-1080).
 *
 * WHY A SWITCH DOES NOT REDRAW A ROW HERE. The switches write one setting, and one value of
 * it moves any number of rows at once, while a source change is answered with one row
 * mutation. So the table does not react to the setting: the screen reads whether a method is
 * on from the set the settings library sends every connection after each change
 * ({@see EnabledAuthMethods}), and {@see enabled} here is that set as it stood when the
 * window was drawn. What the table does react to is a provider's own row - a client pair
 * entered or cleared moves that provider's readiness, and that one row redraws.
 *
 * A project activates the table by registering it under a table key and binding that key to
 * the sign-in methods page in {@see Hilos::PAGE_TABLES}.
 */
class HilosSecuritySignInMethodsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecuritySignInMethods';

    /** Wire slot the row payload rides under; must match the frontend methods slot. */
    private const string ROW_SLOT = 'method';

    /** Screen names of the built-in methods; a provider is named by its directory. */
    private const array LABELS = [
        AuthMethodKey::PASSWORD => 'Password',
        AuthMethodKey::PASSKEY => 'Passkey',
        AuthMethodKey::MAGIC_LINK => 'Email link',
        AuthMethodKey::SMS => 'Phone code',
    ];

    /**
     * Declares how many rows the first window of the methods table carries.
     *
     * A project wires a handful of methods, so one window holds all of them.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Builds a method row mutation from a change of a provider's row.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Provider method row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a provider's row or the method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     * @throws LogicException When the collection classes are misconfigured
     * @throws InvalidArgumentException When the provider row lookup is given an invalid query
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::oauthProviders) {
            return null;
        }

        $providerKey = HilosSecurityOAuthProvidersTable::providerKeyFromSourceChange($change);
        if ($providerKey === null || !in_array($providerKey, $this->methodKeys(), true)) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $providerKey,
            $this->rowForMethod($providerKey, EnabledAuthMethods::keys()),
        );
    }

    /**
     * Serializes one method row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Method table row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Queries the method rows, one per wired method, in directory order.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Methods table snapshot
     * @throws DatabaseException When the method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $enabled = EnabledAuthMethods::keys();
        $rows = [];
        foreach ($this->methodKeys() as $methodKey) {
            $rows[] = $this->rowForMethod($methodKey, $enabled)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Declares what a method row is searched by: its key and its name.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosSecuritySignInMethodsTableRow::methodKey => HilosSecuritySignInMethodsTableRow::methodKey,
            HilosSecuritySignInMethodsTableRow::label => HilosSecuritySignInMethodsTableRow::label,
        ];
    }

    /**
     * Configures the row shape used by the methods table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSecuritySignInMethodsTableRow::class);
    }

    /**
     * The wired method keys, in directory order.
     *
     * A seam the framework reads from the project directory; tests bind an in-memory set.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected function methodKeys(): array
    {
        return Hilos::authMethodDirectoryClass()::keys();
    }

    /**
     * Projects one wired method into a table row.
     *
     * @param string $methodKey Method to project
     * @param list<string> $enabled Enabled method keys, read once for the whole window
     * @return HilosSecuritySignInMethodsTableRow Method table row
     */
    private function rowForMethod(string $methodKey, array $enabled): HilosSecuritySignInMethodsTableRow
    {
        $isProvider = str_starts_with($methodKey, AuthMethodKey::OAUTH_PREFIX);

        return new HilosSecuritySignInMethodsTableRow(
            methodKey: $methodKey,
            label: $isProvider
                ? Hilos::oauthProviderDirectoryClass()::get($methodKey)?->label ?? $methodKey
                : self::LABELS[$methodKey] ?? $methodKey,
            enabled: in_array($methodKey, $enabled, true),
            ready: AuthMethodReadiness::isReady($methodKey),
            providerKey: $isProvider ? $methodKey : null,
        );
    }
}
