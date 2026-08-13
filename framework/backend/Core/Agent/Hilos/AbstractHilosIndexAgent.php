<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use DateTimeImmutable;
use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Hilos;
use Hilos\Notification\DeliveryLogPruner;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationCommandConstants;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
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
    use ProtectedModeTestDriverTrait;

    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * The test-only emit command routed here (HIL-514). It lands on this agent rather than
     * on a notification-owned one because there is none: {@see HilosNotifier} is a worker
     * seam any process calls, and this agent is the only framework-owned worker the Hilos
     * index always has. Carries a plain payload, so it declares no inner DTO. Inherited by
     * every project agent, so a demo activates the command by extending this class alone.
     *
     * Being inherited everywhere is also why the handler repeats the test-only refusal the
     * CLI already performs: the route exists in every project, and the command socket
     * authenticates nobody, so the CLI-side guard alone would leave the emit reachable on a
     * production node by anyone who can open the port.
     *
     * The protected-mode trio (HIL-344, HIL-481) rides the same inheritance for the same reason -
     * chat, simple-todo and simple-poll get a freeze they can drive by extending this class
     * alone. The inspector is not among them: it is answered by the master, because a freeze
     * stops every agent but the initiator. The operator commands are not either: they belong to
     * the agent that runs real operations, and a command routes to exactly one agent type.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::NOTIFICATION_TEST_EMIT,
        CliCommands::PROTECTED_MODE_TEST_ENTER,
        CliCommands::PROTECTED_MODE_TEST_LEAVE,
        CliCommands::PROTECTED_MODE_TEST_OPEN,
    ];

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
     * Runs the due-once-a-day delivery-log prune and finishes any protected-mode drive in flight.
     */
    public function onTick(): void
    {
        parent::onTick();

        $this->tickProtectedModeTestDriver();

        if ($this->deliveryLogPruneRule !== null && $this->deliveryLogPruneRule->shouldRun()) {
            $this->pruneDeliveryLog();
        }
    }

    /**
     * Routes the command-channel commands declared in {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once: a CLI parked on the command socket learns the outcome
     * instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($this->isProtectedModeTestCommand($data->command)) {
            $this->handleProtectedModeTestCommand($data);

            return;
        }

        if ($data->command !== CliCommands::NOTIFICATION_TEST_EMIT) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

            return;
        }

        $this->handleNotificationEmit($data);
    }

    /**
     * Emits one notification from the payload and reports what it produced (HIL-514).
     *
     * The whole point is that this runs in a worker: the emit seam writes the durable row,
     * fans the live in-app signal, and dispatches channels exactly as a product caller
     * would, which a CLI process could not do. The queued channels are then read back from
     * the delivery journal rather than assumed from the dispatch, so the reply reports what
     * the database holds - the difference between "no channel was queued at all" and "the
     * channel was queued and its agent did not deliver".
     *
     * A failed emit and a failed read-back are answered apart on purpose: the second means
     * the notification does exist, and reporting it as an emit failure would send a caller
     * looking for a row that is already there.
     *
     * @param CommandRequestDTO $data Command request carrying the draft fields
     */
    private function handleNotificationEmit(CommandRequestDTO $data): void
    {
        if (!$this->testOnlyCommandsAdmitted()) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                CliCommands::NOTIFICATION_TEST_EMIT . ' is test-only and refuses on a production-like environment',
            ));

            return;
        }

        $notifier = Hilos::$notify;
        if ($notifier === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Notifications are not configured'));

            return;
        }

        $payload = $data->payload;
        $userId = $payload[NotificationCommandConstants::FIELD_USER_ID] ?? null;
        $type = $payload[NotificationCommandConstants::FIELD_TYPE] ?? null;
        $title = $payload[NotificationCommandConstants::FIELD_TITLE] ?? null;
        if (!is_int($userId) || $userId <= 0 || !is_string($type) || $type === '' || !is_string($title) || $title === '') {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Emit needs a positive userId and a non-empty type and title',
            ));

            return;
        }

        $severity = $payload[NotificationCommandConstants::FIELD_SEVERITY] ?? null;
        $body = $payload[NotificationCommandConstants::FIELD_BODY] ?? null;
        $channels = $payload[NotificationCommandConstants::FIELD_CHANNELS] ?? null;

        try {
            $notificationId = $notifier->emit(new NotificationDraft(
                userId: $userId,
                type: $type,
                title: $title,
                severity: is_string($severity) ? $severity : NotificationSeverity::INFO,
                body: is_string($body) ? $body : null,
                channels: is_array($channels) && $channels !== []
                    ? array_values(array_map(strval(...), $channels))
                    : null,
            ));
        } catch (Throwable $e) {
            // Deliberately does not claim nothing was written: emit() persists the row and
            // fans the in-app signal before it dispatches channels, so a failure here can
            // still leave a notification behind, and a caller told otherwise would retry.
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Emit did not complete (a notification may already exist): ' . $e->getMessage(),
            ));

            return;
        }

        try {
            $queued = $this->queuedChannels($notificationId);
        } catch (Throwable $e) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                "Notification {$notificationId} was emitted, but its deliveries are unreadable: {$e->getMessage()}",
            ));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            NotificationCommandConstants::FIELD_NOTIFICATION_ID => $notificationId,
            NotificationCommandConstants::FIELD_QUEUED_CHANNELS => $queued,
        ]));
    }

    /**
     * Whether this node's environment admits a test-only command at all.
     *
     * The agent-side half of the CLI's test-only refusal, and deliberately the SAME
     * verdict, not a stricter one: it reads the same `APP_ENV` through the same catalog, so
     * a node that never sets the variable is admitted here exactly as it is admitted in the
     * CLI (the catalog answers `dev`). Demanding an explicitly set value instead would
     * refuse on every demo stand, none of which sets it, and would leave the two halves of
     * one contract disagreeing.
     *
     * What it does add is a floor under the unreadable case: a catalog or type error means
     * the environment is unknown, and an unknown environment is not evidence of a test node.
     * The two mistakes do not cost the same - refusing on a stand costs a puzzled error
     * line, admitting on a production node sends real mail to a real person.
     *
     * @return bool True when APP_ENV resolves to a known, non-production-like environment
     */
    private function testOnlyCommandsAdmitted(): bool
    {
        try {
            $appEnv = AppEnv::fromString(Hilos::$env?->string(EnvConstants::APP_ENV));
        } catch (Throwable) {
            return false;
        }

        return $appEnv !== null && !$appEnv->isProductionLike();
    }

    /**
     * Names the registered channels that received a delivery row for one notification.
     *
     * Asks the journal per registered channel instead of listing the notification's rows,
     * because a delivery is only meaningful against a channel the project still registers.
     * An empty registry short-circuits before the table is touched, so a project that never
     * activated the delivery table answers "nothing queued" rather than raising.
     *
     * @param int $notificationId Notification whose deliveries are read back
     * @return list<string> Channel names holding a delivery row, in registry order
     * @throws DatabaseException When the delivery lookup query fails
     * @throws TableNotActivatedException When a channel is registered but the table is not activated
     */
    private function queuedChannels(int $notificationId): array
    {
        $deliveries = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$deliveries instanceof ObjectNotificationDeliveries) {
            return [];
        }

        $queued = [];
        foreach (array_keys(Hilos::notificationChannelRegistryClass()::all()) as $channel) {
            if ($deliveries->findFor($notificationId, $channel) !== null) {
                $queued[] = $channel;
            }
        }

        return $queued;
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

            $deleted = new DeliveryLogPruner()->prune(
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
