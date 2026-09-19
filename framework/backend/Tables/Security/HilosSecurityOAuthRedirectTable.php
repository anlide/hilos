<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
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
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Framework OAuth return-address table: the one address every provider redirects back to (HIL-286).
 *
 * A self-snapshot table of exactly one row, drawn above the providers list: the effective
 * address and the layer it comes from, as {@see OAuthConfigResolver::resolveRedirectUri()}
 * resolves it. It is a table and not a field of the page because every value an admin
 * screen shows reaches it this way - a snapshot on subscribe and a redraw after the write,
 * which the tracked action waits for. The writable source behind the row is the settings
 * row {@see OAuthSettingsCatalog::REDIRECT_URI_KEY}, so the table reacts to changes of
 * that one setting.
 *
 * A project activates the table by registering it under a table key and binding that key
 * to the OAuth providers page in {@see Hilos::PAGE_TABLES}.
 */
class HilosSecurityOAuthRedirectTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosSecurityOauthRedirect';

    /** Wire slot the row payload rides under; must match the frontend return-address slot. */
    private const string ROW_SLOT = 'redirect';

    /**
     * Declares how many rows the first window carries: the table has one.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 1;
    }

    /**
     * Builds the row mutation from a change of the return-address setting.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Row mutation, or null when the change is about another setting
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $change->row[ObjectSetting::key] ?? null;
        if ($key !== OAuthSettingsCatalog::REDIRECT_URI_KEY && !$this->isRedirectSettingRow($change->sourceId)) {
            return null;
        }

        return $this->mutation(TableMutationType::Update, OAuthSettingsCatalog::REDIRECT_URI_KEY, $this->row());
    }

    /**
     * Serializes the row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Return-address row from this table's snapshot or mutation
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
     * Queries the one row.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Return-address table snapshot
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([$this->row()->toArray()], $query);
    }

    /**
     * Configures the row shape used by the return-address table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSecurityOAuthRedirectTableRow::class);
    }

    /**
     * Resolves the return address into the table's one row.
     *
     * @return HilosSecurityOAuthRedirectTableRow The row
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function row(): HilosSecurityOAuthRedirectTableRow
    {
        $resolved = new OAuthConfigResolver()->resolveRedirectUri();

        return new HilosSecurityOAuthRedirectTableRow(
            rowKey: OAuthSettingsCatalog::REDIRECT_URI_KEY,
            value: (string) $resolved->value,
            source: $resolved->source->value,
            setState: $resolved->isSet,
        );
    }

    /**
     * Whether a settings row named only by its id is the return-address setting.
     *
     * An update carries only the columns that moved, and the key never moves, so an update
     * of the address arrives without its key and is recognized by its row.
     *
     * @param string $sourceId Settings row id
     * @return bool True when the row is the return-address setting
     * @throws DatabaseException When the settings lookup fails
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function isRedirectSettingRow(string $sourceId): bool
    {
        if (!Hilos::$db instanceof HilosDbContext || !ctype_digit($sourceId)) {
            return false;
        }

        return Hilos::$db->settings[(int) $sourceId]?->key === OAuthSettingsCatalog::REDIRECT_URI_KEY;
    }
}
