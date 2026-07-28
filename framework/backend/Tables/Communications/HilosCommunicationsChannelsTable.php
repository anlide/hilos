<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Notification\Delivery\ChannelConfigSource;
use Hilos\Notification\Delivery\DeliveryChannelSettings;

/**
 * Framework communications channels hub table: one row per registered channel (HIL-200).
 *
 * A self-snapshot table built from the project's channel registry, not a DB source:
 * each row projects a channel descriptor plus its resolved config — global
 * enablement, whether every config field resolved to a value, and the transport
 * driver. Because the rows come from the registry, a new channel (sms 285, push
 * 199) appears here without any table edit. The single writable source behind a row
 * is the settings collection (the admin enablement/override keys), so the table
 * reacts to settings changes to redraw the affected channel row.
 *
 * A project activates the table by registering it under a table key and binding
 * that key to the communications hub page in {@see \Hilos\Hilos::PAGE_TABLES}.
 */
class HilosCommunicationsChannelsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosCommunicationsChannels';

    /** Wire slot the row payload rides under; must match the frontend channels slot. */
    private const string ROW_SLOT = 'channel';

    /**
     * Builds a channels row mutation from a settings source change.
     *
     * A change to a channel's enablement or any of its field keys redraws that one
     * channel row (enablement and the configured/missing counters can shift).
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Channel row mutation, or null when the change does not affect this table
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $this->settingKeyFromSourceChange($change);
        $descriptor = $key === '' ? null : $this->channelForSettingKey($key);
        if ($descriptor === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $descriptor->name(),
            $this->rowForChannel($descriptor),
        );
    }

    /**
     * Serializes one channel row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Channel table row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Queries the channels hub rows, one per registered channel.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Channels table snapshot
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach ($this->channels() as $descriptor) {
            $rows[] = $this->rowForChannel($descriptor)->toArray();
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures the row shape used by the channels table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosCommunicationsChannelsTableRow::class);
    }

    /**
     * The registered delivery channel descriptors.
     *
     * A seam the framework reads from the project channel registry; tests bind an
     * in-memory set.
     *
     * @return iterable<string, AbstractDeliveryChannel> Channel descriptors keyed by name
     */
    protected function channels(): iterable
    {
        return Hilos::notificationChannelRegistryClass()::all();
    }

    /**
     * Projects a channel descriptor and its resolved config into a table row.
     *
     * @param AbstractDeliveryChannel $descriptor Channel descriptor
     * @return HilosCommunicationsChannelsTableRow Channel table row
     * @throws DatabaseException When persisted settings cannot be read
     * @throws SettingException When settings catalog metadata or value is invalid
     * @throws EnvException When an env-backed field value is invalid for its type
     */
    private function rowForChannel(AbstractDeliveryChannel $descriptor): HilosCommunicationsChannelsTableRow
    {
        $resolver = new ChannelConfigResolver();
        $missing = 0;
        foreach ($descriptor->configFields() as $field) {
            if ($resolver->resolve($descriptor->name(), $field)->source === ChannelConfigSource::DEFAULT) {
                $missing++;
            }
        }

        return new HilosCommunicationsChannelsTableRow(
            channel: $descriptor->name(),
            label: $descriptor->label(),
            enabled: $this->resolveEnabled($descriptor),
            configured: $missing === 0,
            driver: $descriptor->driver(),
            missingFields: $missing,
        );
    }

    /**
     * Reads a channel's global-enablement setting, defaulting to disabled.
     *
     * Only a persisted admin override switches a channel on; with no DB context (or
     * no override) the channel reads disabled, the catalog default.
     *
     * @param AbstractDeliveryChannel $descriptor Channel descriptor
     * @return bool True when the channel is globally enabled
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings value is invalid for its type
     */
    private function resolveEnabled(AbstractDeliveryChannel $descriptor): bool
    {
        if (Hilos::$setting === null || !Hilos::$db instanceof HilosDbContext) {
            return false;
        }

        $key = $descriptor->enabledSettingKey();

        return Hilos::$db->settings[$key]?->value !== null && Hilos::$setting[$key]->bool();
    }

    /**
     * Finds the channel a settings key belongs to, or null when none.
     *
     * @param string $key Setting key
     * @return ?AbstractDeliveryChannel Owning channel descriptor, or null
     */
    private function channelForSettingKey(string $key): ?AbstractDeliveryChannel
    {
        foreach ($this->channels() as $descriptor) {
            if ($key === $descriptor->enabledSettingKey()) {
                return $descriptor;
            }

            foreach ($descriptor->configFields() as $field) {
                if ($key === DeliveryChannelSettings::fieldKey($descriptor->name(), $field->key)) {
                    return $descriptor;
                }
            }
        }

        return null;
    }

    /**
     * Resolves the settings key carried by a settings source change.
     *
     * @param SourceChange $change Settings source change
     * @return string Setting key, or an empty string when it cannot be resolved
     */
    private function settingKeyFromSourceChange(SourceChange $change): string
    {
        $key = $change->row[ObjectSetting::key] ?? null;
        if (is_string($key) || is_int($key)) {
            return (string) $key;
        }

        return $change->sourceId;
    }
}
