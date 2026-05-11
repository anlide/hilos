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
 * Rule projecting one registered table onto a page subscription.
 *
 * Snapshot fetches the full table snapshot from the registered table.
 * Broadcast resolves the source change through the table's own source-event
 * mutation builder.
 *
 * Triggers list every DB/RT source key the table observes. The table itself
 * decides how each kind of change shapes the row mutation.
 */
final readonly class TableRule implements ProjectionRule
{
    /**
     * Creates a table projection rule for one registered table.
     *
     * @param string $tableKey Table key registered in the table context
     * @param list<string> $triggers Source keys this table reacts to (DB and/or RT)
     * @param string $wireSignalName Signal name used for table mutation deliveries
     */
    public function __construct(
        public string $tableKey,
        public array $triggers,
        public string $wireSignalName = HilosSignalConstants::TABLE_MUTATION,
    ) {
    }

    /**
     * Returns source keys observed by the table definition.
     *
     * @return list<string> DB/RT source keys that can affect this table
     */
    public function sourceTriggers(): array
    {
        return $this->triggers;
    }

    /**
     * Adds the registered table's full snapshot when table context is available.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Snapshot accumulator for the current subscription
     * @param string $acceptKey Subscribing WebSocket accept key; unused for table snapshots
     * @param PageRouteParams $params Page route params; unused for table snapshots
     */
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

    /**
     * Yields table mutation deliveries for subscribed accept keys.
     *
     * @param SourceChange $change Matching DB/RT source change
     * @param list<string> $audienceAcceptKeys Accept keys subscribed to the owning page or group
     * @return iterable<ProjectionDelivery> Addressed table mutation deliveries
     */
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
