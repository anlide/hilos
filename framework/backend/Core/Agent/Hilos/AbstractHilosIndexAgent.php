<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use DateTimeImmutable;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Notification\DeliveryLogPruner;
use Throwable;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings, i18n, and non-logs admin pages.
 *
 * Projects must extend this class to provide a concrete agent for Hilos index-scoped pages.
 * Logs overview uses {@see AbstractHilosLogsAgent} separately.
 *
 * This is also the periodic owner of the channel delivery journal: on a daily cron it
 * prunes terminal delivery rows past their retention window ({@see DeliveryLogPruner}),
 * the same tick-plus-cron mechanism the backup agent rotates by. The prune is an
 * idempotent, bounded batch delete, so it stays safe even if more than one index
 * agent runs it; a project that does not catalog the retention setting is skipped.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /** Cron expression for the daily delivery-log prune (03:20). */
    private const string DELIVERY_LOG_PRUNE_SCHEDULE = '20 3 * * *';

    /** @var ?CronRule Once-per-day guard for the delivery-log prune */
    private ?CronRule $deliveryLogPruneRule = null;

    /**
     * Registers framework settings as the Hilos index DB truth source and arms the prune cron.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(HilosDbContext::settings);
        $this->deliveryLogPruneRule = new CronRule('hilos_delivery_log_prune', self::DELIVERY_LOG_PRUNE_SCHEDULE);
    }

    /**
     * Runs the due-once-a-day delivery-log prune.
     */
    public function onTick(): void
    {
        parent::onTick();

        if ($this->deliveryLogPruneRule !== null && $this->deliveryLogPruneRule->shouldRun()) {
            $this->pruneDeliveryLog();
        }
    }

    /**
     * Prunes the delivery journal to its retention window, swallowing and logging any failure.
     *
     * Reads the retention setting only when the project catalogs it; a retention of 0
     * disables cleanup inside {@see DeliveryLogPruner::prune()}. Any failure is logged
     * and swallowed so a prune error never breaks the agent loop.
     */
    private function pruneDeliveryLog(): void
    {
        try {
            $setting = Hilos::$setting;
            if ($setting === null || !isset($setting[DeliveryLogPruner::RETENTION_SETTING_KEY])) {
                return;
            }

            $deleted = (new DeliveryLogPruner())->prune(
                $setting[DeliveryLogPruner::RETENTION_SETTING_KEY]->int(),
                new DateTimeImmutable(),
            );

            if ($deleted > 0) {
                $this->logAgentInfo("Delivery-log prune removed {$deleted} rows");
            }
        } catch (Throwable $e) {
            $this->logAgentError('Delivery-log prune failed: ' . $e->getMessage());
        }
    }
}
