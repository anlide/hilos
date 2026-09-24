<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
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
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\Object\Item\OAuthProvider as ObjectOAuthProvider;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Framework OAuth provider fields table: one row per field of every declared provider (HIL-286).
 *
 * A self-snapshot table built from the project's provider directory: each row projects
 * one {@see OAuthConfigField} of one provider plus its value and source from
 * {@see OAuthConfigResolver}. The client secret's value is never in a row - it is
 * write-only from the admin, and its row carries only whether one is in force. The
 * provider screen narrows the table to its provider through the
 * {@see HilosSecurityOAuthProvidersTable::FILTER_PROVIDER} key, preset from its route.
 *
 * The writable source behind a row is the provider's row in hilos_oauth_provider. An
 * update names the columns that moved, and each column is one field row; the secret moves
 * no mapped column, and its write is announced with an empty diff via db_sync_updated
 * ({@see ObjectOAuthProvider::writeClientSecret()} via {@see Object_::announceUnmappedUpdate()}),
 * which redraws the secret's row. A freshly created provider row is accepted as Create too.
 *
 * A project activates the table by registering it under a table key and binding that key
 * to the OAuth provider page in {@see Hilos::PAGE_TABLES}.
 */
class HilosSecurityOAuthProviderFieldsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecurityOauthProviderFields';

    /** Wire slot the row payload rides under; must match the frontend fields slot. */
    private const string ROW_SLOT = 'field';

    /** Separator between the provider key and the field name in a row key. */
    private const string ROW_KEY_SEPARATOR = '/';

    /**
     * Declares how many rows the first window of the fields table carries.
     *
     * The provider screen narrows the table to one provider, which has three fields.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Builds a field row mutation from a change of the provider rows.
     *
     * Accepts row updates and freshly created provider rows, returning an update mutation for the affected field row.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Field row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or a descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::oauthProviders
            || ($change->mutationType !== TableMutationType::Update && $change->mutationType !== TableMutationType::Create)
        ) {
            return null;
        }

        $providerKey = HilosSecurityOAuthProvidersTable::providerKeyFromSourceChange($change);
        $descriptor = $providerKey === null ? null : ($this->providers()[$providerKey] ?? null);
        if ($descriptor === null) {
            return null;
        }

        $field = self::fieldForChange($change);
        if ($field === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            self::rowKeyFor($descriptor, $field),
            $this->rowForField($descriptor, $field),
        );
    }

    /**
     * Serializes one field row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Field table row from this table's snapshot or mutation
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
     * Queries the field rows, one per field of every declared provider.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Fields table snapshot
     * @throws DatabaseException When a provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or a descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $provider = HilosSecurityOAuthProvidersTable::filterString($query, HilosSecurityOAuthProvidersTable::FILTER_PROVIDER);
        $rows = [];
        foreach ($this->providers() as $descriptor) {
            if ($provider !== null && $descriptor->key !== $provider) {
                continue;
            }
            foreach (OAuthConfigField::cases() as $field) {
                $rows[] = $this->rowForField($descriptor, $field)->toArray();
            }
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Declares the fields table's sortable columns, which here are the row payload keys themselves.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosSecurityOAuthProviderFieldsTableRow::providerKey => HilosSecurityOAuthProviderFieldsTableRow::providerKey,
            HilosSecurityOAuthProviderFieldsTableRow::field => HilosSecurityOAuthProviderFieldsTableRow::field,
            HilosSecurityOAuthProviderFieldsTableRow::label => HilosSecurityOAuthProviderFieldsTableRow::label,
        ];
    }

    /**
     * Configures the row shape used by the fields table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSecurityOAuthProviderFieldsTableRow::class);
    }

    /**
     * The declared provider descriptors, in directory order.
     *
     * A seam the framework reads from the project directory; tests bind an in-memory set.
     *
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected function providers(): array
    {
        return Hilos::oauthProviderDirectoryClass()::all();
    }

    /**
     * Projects one field of one provider and its resolved value into a table row.
     *
     * @param OAuthProviderDescriptor $descriptor Owning provider
     * @param OAuthConfigField $field Field to project
     * @return HilosSecurityOAuthProviderFieldsTableRow Field table row
     * @throws DatabaseException When the provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or the descriptor has no recipe
     * @throws InvalidArgumentException When the row lookup is given an invalid query
     */
    private function rowForField(OAuthProviderDescriptor $descriptor, OAuthConfigField $field): HilosSecurityOAuthProviderFieldsTableRow
    {
        $resolved = new OAuthConfigResolver()->resolve($descriptor, $field);

        return new HilosSecurityOAuthProviderFieldsTableRow(
            rowKey: self::rowKeyFor($descriptor, $field),
            providerKey: $descriptor->key,
            field: $field->value,
            label: $field->label(),
            type: SettingsCatalogConstants::TYPE_STRING,
            secret: $field->isSecret(),
            value: $resolved->value,
            source: $resolved->source->value,
            setState: $resolved->isSet,
        );
    }

    /**
     * Names the field an update or create of a provider row moved.
     *
     * For an update, an empty diff is the secret's own announcement; otherwise the first mapped
     * column of the diff names the field. For a create (such as a freshly added provider row merged
     * with its initial field write), a non-empty client_id or scope names that field, and an empty
     * or null-only row means the row was created for the secret.
     *
     * @param SourceChange $change Update or create of a provider row
     * @return ?OAuthConfigField Field whose row redraws, or null when no field moved
     */
    private static function fieldForChange(SourceChange $change): ?OAuthConfigField
    {
        if ($change->mutationType === TableMutationType::Create) {
            $clientId = $change->row[EntityOAuthProvider::client_id] ?? null;
            if (is_string($clientId) && $clientId !== '') {
                return OAuthConfigField::CLIENT_ID;
            }
            $scope = $change->row[EntityOAuthProvider::scope] ?? null;
            if (is_string($scope) && $scope !== '') {
                return OAuthConfigField::SCOPE;
            }

            return OAuthConfigField::CLIENT_SECRET;
        }

        if ($change->row === []) {
            return OAuthConfigField::CLIENT_SECRET;
        }
        if (array_key_exists(EntityOAuthProvider::client_id, $change->row)) {
            return OAuthConfigField::CLIENT_ID;
        }
        if (array_key_exists(EntityOAuthProvider::scope, $change->row)) {
            return OAuthConfigField::SCOPE;
        }

        return null;
    }

    /**
     * Builds the row key of one field of one provider.
     *
     * @param OAuthProviderDescriptor $descriptor Owning provider
     * @param OAuthConfigField $field Field
     * @return string Row key
     */
    private static function rowKeyFor(OAuthProviderDescriptor $descriptor, OAuthConfigField $field): string
    {
        return $descriptor->key . self::ROW_KEY_SEPARATOR . $field->value;
    }
}
