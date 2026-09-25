<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
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
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * Declared protected operations narrowed by the installation setting (HIL-495).
 */
final class HilosSecurityStepUpTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'hilosSecurityStepUp';
    private const string ROW_SLOT = 'operation';
    private const string OWNER_FRAMEWORK = 'framework';
    private const string OWNER_PROJECT = 'project';

    /** @return int Rows in the initial window */
    public function windowSize(): int
    {
        return count(Hilos::stepUpOperationDirectoryClass()::keys());
    }

    /**
     * @param SourceChange $change Settings source change
     * @return ?TableRowMutationDTO Changed operation row, or null for another setting
     * @throws DatabaseException When the setting or operation row cannot be read
     * @throws InvalidArgumentException When a lookup or operation declaration is invalid
     * @throws LogicException When collection metadata is incomplete
     * @throws SettingException When the setting catalog or stored value is invalid
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }
        $key = $change->row[ObjectSetting::key] ?? $this->settingKeyOf($change->sourceId);
        if ($key !== StepUpSettings::DISABLED_KEY) {
            return null;
        }

        $beforeValue = $change->previous[ObjectSetting::value] ?? null;
        $afterValue = $change->row[ObjectSetting::value] ?? null;
        $before = is_string($beforeValue) ? StepUpSettings::parse($beforeValue) : [];
        $after = is_string($afterValue) ? StepUpSettings::parse($afterValue) : [];
        foreach (Hilos::stepUpOperationDirectoryClass()::keys() as $operationKey) {
            if (in_array($operationKey, $before, true) !== in_array($operationKey, $after, true)) {
                return $this->mutation(TableMutationType::Update, $operationKey, $this->row($operationKey));
            }
        }

        return null;
    }

    /**
     * @param AbstractTableRow $row Step-up row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When the row carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /**
     * @param TableQueryDTO $query Table query
     * @return TableSnapshotDTO Operation snapshot
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a search field is unavailable
     * @throws DatabaseException When an operation row cannot be read
     * @throws InvalidArgumentException When an operation declaration is invalid
     * @throws SettingException When the setting catalog or stored value is invalid
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach (Hilos::stepUpOperationDirectoryClass()::keys() as $operationKey) {
            $rows[] = $this->row($operationKey)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    protected function init(): void
    {
        $this->setRowClass(HilosSecurityStepUpTableRow::class);
    }

    /**
     * @param string $operationKey Declared operation key
     * @return HilosSecurityStepUpTableRow Operation row
     */
    private function row(string $operationKey): HilosSecurityStepUpTableRow
    {
        $directory = Hilos::stepUpOperationDirectoryClass();
        $operation = $directory::get($operationKey);

        return new HilosSecurityStepUpTableRow(
            $operationKey,
            $operation->label,
            $directory::isFramework($operationKey) ? self::OWNER_FRAMEWORK : self::OWNER_PROJECT,
            StepUpSettings::isEnabled($operationKey),
        );
    }

    /**
     * @param string $sourceId Settings row id
     * @return ?string Setting key, or null when it cannot be resolved
     */
    private function settingKeyOf(string $sourceId): ?string
    {
        if (!Hilos::$db instanceof HilosDbContext || !ctype_digit($sourceId)) {
            return null;
        }

        return Hilos::$db->settings[(int)$sourceId]?->key;
    }
}
