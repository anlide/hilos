<?php

declare(strict_types=1);

namespace Hilos\Core\Projection\Rule;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\ProjectionDelivery;
use Hilos\Core\Projection\ProjectionRule;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Hilos;

/**
 * Rule projecting one TableDefinition onto a page subscription.
 *
 * Snapshot fetches the full table snapshot through {@see \Hilos\Core\Table\Definition\TableDefinition::getFullSnapshot()}.
 * Broadcast resolves the source change through the table's own
 * {@see \Hilos\Core\Table\Definition\TableDefinition::buildMutationForSourceEvent()}.
 *
 * Triggers list every DB/RT source key the table observes. The table itself
 * decides how each kind of change shapes the row mutation.
 */
final readonly class TableRule implements ProjectionRule
{
    /**
     * @param string $tableKey Table key registered in TableContext
     * @param list<string> $triggers Source keys this table reacts to (DB and/or RT)
     */
    public function __construct(
        public string $tableKey,
        public array $triggers,
        public string $wireSignalName = HilosSignalConstants::TABLE_MUTATION,
    ) {
    }

    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    public function contributeToSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): void {
        if (Hilos::$table === null) {
            return;
        }
        $table = Hilos::$table->get($this->tableKey);
        if ($table === null) {
            return;
        }
        $accumulator->addTableSnapshot($this->tableKey, $table->getFullSnapshot());
    }

    public function buildBroadcastDeliveries(SourceChange $change, array $audienceAcceptKeys): iterable
    {
        if (Hilos::$table === null || $audienceAcceptKeys === []) {
            return;
        }

        foreach (Hilos::$table->buildMutationSignalsForSourceEvent($change, [$this->tableKey]) as $tableSignal) {
            foreach (array_values(array_unique($audienceAcceptKeys)) as $acceptKey) {
                if ($acceptKey === '') {
                    continue;
                }
                yield new ProjectionDelivery($this->wireSignalName, $tableSignal, $acceptKey);
            }
        }
    }
}
