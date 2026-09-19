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
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Framework OAuth providers table: one row per provider the project declares (HIL-286).
 *
 * A self-snapshot table built from the project's provider directory, not a DB source:
 * each row projects a descriptor plus what {@see OAuthConfigResolver} makes of it -
 * whether the provider can sign anyone in, how many required fields are still empty,
 * the state of its secret and where its client id comes from - and its recipe, shown as
 * reference on the provider screen. A provider the directory gains appears here without
 * any table edit. The writable source behind a row is the provider's row in
 * hilos_oauth_provider, so the table reacts to changes of that collection.
 *
 * The providers list shows every row; the provider screen narrows the table to its own
 * provider through the {@see self::FILTER_PROVIDER} key of the filter map, preset from its
 * route. A route naming a provider the project does not declare narrows it to nothing.
 *
 * A project activates the table by registering it under a table key and binding that key
 * to the OAuth pages in {@see Hilos::PAGE_TABLES}.
 */
class HilosSecurityOAuthProvidersTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecurityOauthProviders';

    /** Filter-map key: narrow the providers to one, the one the provider screen's route names. */
    public const string FILTER_PROVIDER = 'provider';

    /** Wire slot the row payload rides under; must match the frontend providers slot. */
    private const string ROW_SLOT = 'provider';

    /**
     * Declares how many rows the first window of the providers table carries.
     *
     * A project declares a handful of providers, so one window holds all of them.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Builds a provider row mutation from a change of the provider rows.
     *
     * Any change of a provider's row - a field entered, cleared, or its secret replaced -
     * redraws that one provider row.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Provider row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or a descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::oauthProviders) {
            return null;
        }

        $providerKey = self::providerKeyFromSourceChange($change);
        $descriptor = $providerKey === null ? null : ($this->providers()[$providerKey] ?? null);
        if ($descriptor === null) {
            return null;
        }

        return $this->mutation(TableMutationType::Update, $descriptor->key, $this->rowForProvider($descriptor));
    }

    /**
     * Serializes one provider row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Provider table row from this table's snapshot or mutation
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
     * Queries the provider rows, one per declared provider, in directory order.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Providers table snapshot
     * @throws DatabaseException When a provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or a descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $provider = self::filterString($query, self::FILTER_PROVIDER);
        $rows = [];
        foreach ($this->providers() as $descriptor) {
            if ($provider !== null && $descriptor->key !== $provider) {
                continue;
            }
            $rows[] = $this->rowForProvider($descriptor)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Declares the providers table's sortable columns, which here are the row payload keys themselves.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosSecurityOAuthProvidersTableRow::providerKey => HilosSecurityOAuthProvidersTableRow::providerKey,
            HilosSecurityOAuthProvidersTableRow::label => HilosSecurityOAuthProvidersTableRow::label,
        ];
    }

    /**
     * Declares what a provider row is searched by: its key and its name.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosSecurityOAuthProvidersTableRow::providerKey => HilosSecurityOAuthProvidersTableRow::providerKey,
            HilosSecurityOAuthProvidersTableRow::label => HilosSecurityOAuthProvidersTableRow::label,
        ];
    }

    /**
     * Configures the row shape used by the providers table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSecurityOAuthProvidersTableRow::class);
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
     * Projects a provider descriptor and its resolved configuration into a table row.
     *
     * @param OAuthProviderDescriptor $descriptor Provider to project
     * @return HilosSecurityOAuthProvidersTableRow Provider table row
     * @throws DatabaseException When the provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or the descriptor has no recipe
     * @throws InvalidArgumentException When the row lookup is given an invalid query
     */
    private function rowForProvider(OAuthProviderDescriptor $descriptor): HilosSecurityOAuthProvidersTableRow
    {
        $resolver = new OAuthConfigResolver();
        $clientId = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_ID);
        $secret = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_SECRET);
        $missing = (int) !$clientId->isSet + (int) !$secret->isSet;
        $recipe = $descriptor->recipeConfig();

        return new HilosSecurityOAuthProvidersTableRow(
            providerKey: $descriptor->key,
            label: $descriptor->label,
            builtIn: $descriptor->preset !== null,
            configured: $missing === 0,
            missingFields: $missing,
            secretSet: $secret->isSet,
            clientIdSource: $clientId->source->value,
            authorizeUrl: $recipe->authorizeUrl,
            tokenUrl: $recipe->tokenUrl,
            userInfoUrl: $recipe->userInfoUrl,
            subjectKey: $recipe->subjectKey,
            emailKey: $recipe->emailKey,
            nameKey: $recipe->nameKey,
        );
    }

    /**
     * Resolves the provider key a change of the provider rows is about.
     *
     * A created row carries its key; an update carries only what changed, so its key is
     * read off the row the change names.
     *
     * @param SourceChange $change Change of the provider rows
     * @return ?string Provider key, or null when the row cannot be found
     * @throws DatabaseException When the row lookup fails
     * @throws LogicException When the collection classes are misconfigured
     * @throws InvalidArgumentException When the row lookup is given an invalid query
     */
    public static function providerKeyFromSourceChange(SourceChange $change): ?string
    {
        $key = $change->row[EntityOAuthProvider::provider_key] ?? null;
        if (is_string($key)) {
            return $key;
        }
        if (!Hilos::$db instanceof HilosDbContext || !ctype_digit($change->sourceId)) {
            return null;
        }

        return Hilos::$db->oauthProviders[(int) $change->sourceId]?->providerKey;
    }

    /**
     * Reads one string filter value from the open filter map.
     *
     * @param TableQueryDTO $query Window query
     * @param string $key Filter key
     * @return ?string Trimmed value, or null when the window filters on nothing here
     */
    public static function filterString(TableQueryDTO $query, string $key): ?string
    {
        $value = $query->filter[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
