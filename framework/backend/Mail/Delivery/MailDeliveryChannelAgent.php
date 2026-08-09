<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailConfigException;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Mail\FailedMailTransport;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\MailTransportFactory;
use Hilos\Mail\MailTransportInterface;
use Hilos\Mail\Template\GenericNotificationMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;

/**
 * MailDeliveryChannelAgent - the sharded email delivery agent (HIL-197).
 *
 * The concrete delivery agent for the `email` channel: it owns one shard of the
 * `hilos_mail` pool and turns each dispatched notification into a non-blocking SMTP
 * (or file) send. The base pipeline drives intake, the concurrency pool, bounded
 * retries, and the delivery-row bookkeeping; this leaf supplies the channel descriptor
 * ({@see MailDeliveryChannel}) and wraps one send as a {@see MailDeliveryAttempt},
 * rendering the notification through the mail template registry.
 *
 * Two intakes land on this pool, both routed by the recipient's shard key so all mail
 * for one address stays on one instance:
 *  - input A, {@see HilosSignalConstants::HILOS_MAIL_DELIVER}: a durable notification
 *    delivery, driven by the base pipeline (row bookkeeping, bounded retries);
 *  - input B, {@see HilosSignalConstants::HILOS_MAIL_SEND}: a raw send (Auth codes,
 *    magic links) with no delivery row, driven by this class's own in-memory pool.
 *
 * The raw pool has its own concurrency ceiling ({@see maxConcurrent()}), so at peak this
 * instance may hold up to twice that many transports open (input A plus input B). A raw
 * permanent failure (SMTP 5xx, missing config) fails fast; a transient one retries with
 * backoff up to {@see maxAttempts()}. Raw sends are not recovered across a restart — they
 * have no durable record; a caller of a critical mail resends.
 *
 * Crash recovery of pending input-A rows in onStart is still deferred to a later slice.
 */
class MailDeliveryChannelAgent extends AbstractDeliveryChannelAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MAIL;

    /**
     * Pool routes: both intakes are fanned across the pool by the recipient's shard key.
     *  - HILOS_MAIL_DELIVER (input A) carries a notification delivery;
     *  - HILOS_MAIL_SEND (input B) carries a raw send.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_MAIL_DELIVER => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class,
        ],
        HilosSignalConstants::HILOS_MAIL_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => MailSendSignalData::shardKey,
            AgentSignalConfigKey::DTO => MailSendSignalData::class,
        ],
    ];

    /** Fallback concurrency ceiling when MAIL_MAX_CONCURRENT is unavailable. */
    private const int DEFAULT_MAX_CONCURRENT = 4;

    /** Base retry backoff in milliseconds for raw sends, doubled per prior attempt. */
    private const float RAW_RETRY_BACKOFF_BASE_MS = 1000.0;

    /** The channel descriptor, built once and reused across ticks. */
    private ?MailDeliveryChannel $channel = null;

    /** @var array<int, RawMailSend> Raw-send ops (input B), keyed by a monotonic op id. */
    private array $rawSends = [];

    /** Next op id for a queued raw send. */
    private int $rawNextId = 0;

    /** Whether the static MAIL_* runtime config has been resolved from env yet. */
    private bool $configResolved = false;

    /** Resolved transport config, or null when MAIL_* is invalid (sends then fail permanently). */
    private ?MailTransportConfig $transportConfig = null;

    /** Resolved raw-pool concurrency ceiling, read from env once. */
    private int $resolvedMaxConcurrent = self::DEFAULT_MAX_CONCURRENT;

    /**
     * Binds this pool instance to its shard index.
     *
     * @param string $agentIndex Pool shard index (1..MAIL_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('MailDeliveryChannelAgent requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * Routes a raw send to the in-memory pool; everything else to the delivery pipeline.
     *
     * Input B ({@see MailSendSignalData}) is queued here without a delivery row; any other
     * payload (input A) is handed to the base intake unchanged.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($data->data instanceof MailSendSignalData) {
            $this->enqueueRawSend($data->data);

            return;
        }

        parent::onSignalAgent($data, $source, $name);
    }

    /**
     * Pumps the delivery pipeline, then the raw-send pool.
     */
    public function onTick(): void
    {
        parent::onTick();

        $nowMs = microtime(true) * TimeConstants::MS_PER_SECOND;
        $this->pumpRawInFlight($nowMs);
        $this->startRawQueued($nowMs);
    }

    /**
     * Abandons every in-flight raw send, then lets the base pipeline shut down.
     */
    public function onStop(): void
    {
        foreach ($this->rawSends as $send) {
            $send->attempt?->close();
        }
        $this->rawSends = [];

        parent::onStop();
    }

    /**
     * @return AbstractDeliveryChannel The email channel descriptor
     */
    protected function channel(): AbstractDeliveryChannel
    {
        return $this->channel ??= new MailDeliveryChannel();
    }

    /**
     * Renders the notification and wraps a fresh transport as a non-blocking attempt.
     *
     * The title and body are already localized on the notification, so they pass through
     * the generic notification template verbatim (no locale, project default rendering).
     *
     * @param string $address Resolved recipient email address
     * @param ObjectNotification $notification Notification to render and deliver
     * @return DeliveryAttempt The started email send
     * @throws MailTemplateNotInCatalogException When the generic notification template is absent from the catalog
     * @throws MailTemplateParamMissingException When the notification carries no title to head the email with
     * @throws MailBusyException When the freshly built transport is not idle (never in practice)
     */
    protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        $content = $this->templateRegistry()->render(
            MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [
                GenericNotificationMailTemplate::PARAM_TITLE => $notification->title,
                // external-boundary: body is a nullable column, and the template prints nothing for an empty one
                GenericNotificationMailTemplate::PARAM_BODY => $notification->body ?? '',
            ],
        );

        return new MailDeliveryAttempt(
            $this->createTransport(),
            new EmailMessage($address, $content->subject, $content->text, html: $content->html),
            microtime(true) * TimeConstants::MS_PER_SECOND,
        );
    }

    /**
     * @return int Concurrency ceiling from MAIL_MAX_CONCURRENT, or the default
     */
    protected function maxConcurrent(): int
    {
        $this->resolveConfig();

        return $this->resolvedMaxConcurrent;
    }

    /**
     * Builds the configured mail transport for one send.
     *
     * The transport seam: tests override it to inject a fake transport instead of opening
     * a real SMTP or file send. When the static MAIL_* config is invalid it hands out a
     * {@see FailedMailTransport} so the send settles as a permanent failure rather than
     * throwing the misconfig out of the tick loop (see {@see resolveConfig()}).
     *
     * @return MailTransportInterface A fresh transport built from the MAIL_* config, or a permanently failing one
     */
    protected function createTransport(): MailTransportInterface
    {
        $this->resolveConfig();
        if ($this->transportConfig === null) {
            return new FailedMailTransport();
        }

        return new MailTransportFactory()->create($this->transportConfig);
    }

    /**
     * Resolves the static MAIL_* runtime config once, latching an invalid config.
     *
     * Env is read a single time (fail fast on first use). An invalid MAIL_* value would
     * otherwise throw from the tick loop on every send and crash-loop the worker, losing
     * the in-memory raw pool; instead the failure is logged once (domain reason, no
     * secrets), the transport config is left null, and each send is dropped as a permanent
     * failure through {@see createTransport()}.
     */
    private function resolveConfig(): void
    {
        if ($this->configResolved) {
            return;
        }
        $this->configResolved = true;

        try {
            $config = MailTransportConfig::fromEnv();
            $this->resolvedMaxConcurrent = max(
                1,
                Hilos::$env?->int(EnvConstants::MAIL_MAX_CONCURRENT) ?? self::DEFAULT_MAX_CONCURRENT,
            );
            $this->transportConfig = $config;
        } catch (EnvException | MailConfigException $e) {
            $this->logAgentWarning('mail transport disabled by invalid MAIL_* config: ' . $e->getMessage());
        }
    }

    /**
     * Builds the mail template registry used to render notifications.
     *
     * A project overrides it to render through its own template catalog.
     *
     * @return MailTemplateRegistry Registry over the framework template catalog
     */
    protected function templateRegistry(): MailTemplateRegistry
    {
        return new MailTemplateRegistry();
    }

    /**
     * Renders the raw send and queues it, or drops it when its template cannot be rendered.
     *
     * An unknown template key, or one asked to render without a param it needs, is a caller
     * error and is dropped with a domain-only log (address and key, never the params); a
     * well-formed send joins the raw pool.
     *
     * @param MailSendSignalData $signal Raw-send payload (inline message or template)
     */
    private function enqueueRawSend(MailSendSignalData $signal): void
    {
        try {
            $message = $this->buildRawMessage($signal);
        } catch (MailTemplateNotInCatalogException) {
            $this->logAgentWarning(
                "raw send to '{$signal->to}' dropped: unknown template '{$signal->templateKey}'",
            );

            return;
        } catch (MailTemplateParamMissingException $failure) {
            $this->logAgentWarning(
                "raw send to '{$signal->to}' dropped: {$failure->getMessage()}",
            );

            return;
        }

        $this->rawSends[$this->rawNextId++] = new RawMailSend($message, $signal->templateKey);
    }

    /**
     * Builds the recipient message from a template render or the inline fields.
     *
     * @param MailSendSignalData $signal Raw-send payload
     * @return EmailMessage The message to hand a transport
     * @throws MailTemplateNotInCatalogException When a named template is absent from the catalog
     * @throws MailTemplateParamMissingException When a named template needs a param the payload lacks
     */
    private function buildRawMessage(MailSendSignalData $signal): EmailMessage
    {
        if ($signal->templateKey !== null) {
            $content = $this->templateRegistry()->render($signal->templateKey, $signal->params, $signal->locale);

            return new EmailMessage($signal->to, $content->subject, $content->text, html: $content->html);
        }

        // The payload invariant guarantees the inline pair whenever no template names the content,
        // so the pair is passed through as it is: a broken invariant has to fail loudly here rather
        // than mail an empty subject.
        return new EmailMessage($signal->to, $signal->subject, $signal->text, html: $signal->html);
    }

    /**
     * Advances each in-flight raw send and settles its outcome.
     *
     * A delivered send is dropped; a failed one is retried with backoff unless the failure
     * is permanent or the attempt ceiling is reached, in which case it is logged (address
     * and template key only) and dropped.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function pumpRawInFlight(float $nowMs): void
    {
        foreach ($this->rawSends as $id => $send) {
            $attempt = $send->attempt;
            if ($attempt === null) {
                continue;
            }
            $attempt->tick($nowMs);
            if ($attempt->isBusy()) {
                continue;
            }

            if ($attempt->isDelivered()) {
                $attempt->close();
                unset($this->rawSends[$id]);
                continue;
            }

            $permanent = $attempt->isPermanentFailure();
            $error = $attempt->errorDetail() ?? 'delivery failed';
            $attempt->close();
            $send->attempt = null;

            if ($permanent || $send->attempts >= $this->maxAttempts()) {
                $this->logRawFailure($send, $error);
                unset($this->rawSends[$id]);
                continue;
            }
            $send->nextAttemptMs = $nowMs + $this->rawBackoffMs($send->attempts);
        }
    }

    /**
     * Starts every queued raw send that is due and under the concurrency ceiling.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function startRawQueued(float $nowMs): void
    {
        foreach ($this->rawSends as $send) {
            if ($send->attempt !== null || $nowMs < $send->nextAttemptMs) {
                continue;
            }
            if ($this->rawInFlightCount() >= $this->maxConcurrent()) {
                return;
            }

            $send->attempts++;
            $send->attempt = new MailDeliveryAttempt($this->createTransport(), $send->message, $nowMs);
        }
    }

    /**
     * @return int Number of raw sends with a transport currently in flight
     */
    private function rawInFlightCount(): int
    {
        $count = 0;
        foreach ($this->rawSends as $send) {
            if ($send->attempt !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Exponential retry backoff for the next raw attempt.
     *
     * @param int $attempts Attempts made so far
     * @return float Backoff in milliseconds before the next attempt
     */
    protected function rawBackoffMs(int $attempts): float
    {
        return self::RAW_RETRY_BACKOFF_BASE_MS * (2 ** max(0, $attempts - 1));
    }

    /**
     * Logs a terminal raw-send failure with the address and template key only.
     *
     * The message body and template params are never logged: raw sends carry Auth codes
     * and magic-link tokens.
     *
     * @param RawMailSend $send Failed raw send
     * @param string $error Domain failure sentence
     */
    private function logRawFailure(RawMailSend $send, string $error): void
    {
        $template = $send->templateKey ?? 'inline';
        $this->logAgentWarning("raw send to '{$send->message->to}' (template '{$template}') failed: {$error}");
    }
}
