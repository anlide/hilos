<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\API\Exception\AsyncHttpException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Sms\DirectSmsProvider;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\Exception\SmsException;
use Hilos\Sms\Exception\SmsTemplateNotInCatalogException;
use Hilos\Sms\Exception\SmsTemplateParamMissingException;
use Hilos\Sms\HttpSmsProvider;
use Hilos\Sms\SmsChannelConfig;
use Hilos\Sms\SmsMessage;
use Hilos\Sms\SmsProviderRegistry;
use Hilos\Sms\SmsText;
use Hilos\Sms\Template\GenericNotificationSmsTemplate;
use Hilos\Sms\Template\SmsTemplateCatalogConstants;
use Hilos\Sms\Template\SmsTemplateRegistry;
use Hilos\Socket\SocketException;

/**
 * SmsDeliveryChannelAgent - the sharded SMS delivery agent (HIL-285).
 *
 * The concrete delivery agent for the `sms` channel, mirroring
 * {@see MailDeliveryChannelAgent}: it owns one shard of the `hilos_sms`
 * pool and turns each dispatched notification into a non-blocking HTTP gateway send (or a stub
 * .txt write). The base pipeline drives intake, the concurrency pool, bounded retries, and the
 * delivery-row bookkeeping; this leaf supplies the channel descriptor ({@see SmsDeliveryChannel})
 * and wraps one send as an {@see SmsSendAttempt}, rendering the notification through the SMS
 * template registry and clamping it to one segment.
 *
 * Two intakes land on this pool, both routed by the recipient's shard key so all SMS for one
 * number stays on one instance:
 *  - input A, {@see HilosSignalConstants::HILOS_SMS_DELIVER}: a durable notification delivery,
 *    driven by the base pipeline (row bookkeeping, bounded retries);
 *  - input B, {@see HilosSignalConstants::HILOS_SMS_SEND}: a raw send (Auth login/add codes)
 *    with no delivery row, driven by this class's own in-memory pool.
 *
 * A permanent failure (HTTP 4xx, a provider rejection) fails fast; a transient one (HTTP 5xx,
 * timeout) retries with backoff up to {@see maxAttempts()}. Raw sends are not recovered across
 * a restart - they have no durable record. Crash recovery of pending input-A rows in onStart is
 * still deferred to a later slice.
 */
class SmsDeliveryChannelAgent extends AbstractDeliveryChannelAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SMS;

    /**
     * Pool routes: both intakes are fanned across the pool by the recipient's shard key.
     *  - HILOS_SMS_DELIVER (input A) carries a notification delivery;
     *  - HILOS_SMS_SEND (input B) carries a raw send.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SMS_DELIVER => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class,
        ],
        HilosSignalConstants::HILOS_SMS_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => SmsSendSignalData::shardKey,
            AgentSignalConfigKey::DTO => SmsSendSignalData::class,
        ],
    ];

    /** Concurrency ceiling for both the delivery pool and the raw pool. */
    private const int DEFAULT_MAX_CONCURRENT = 4;

    /** Base retry backoff in milliseconds for raw sends, doubled per prior attempt. */
    private const float RAW_RETRY_BACKOFF_BASE_MS = 1000.0;

    /** The channel descriptor, built once and reused across ticks. */
    private ?SmsDeliveryChannel $channel = null;

    /** @var array<int, RawSmsSend> Raw-send ops (input B), keyed by a monotonic op id. */
    private array $rawSends = [];

    /** Next op id for a queued raw send. */
    private int $rawNextId = 0;

    /** Whether the SMS channel config has been resolved yet. */
    private bool $configResolved = false;

    /** Resolved channel config, or null when it could not be read (sends then fail permanently). */
    private ?SmsChannelConfig $config = null;

    /** Provider registry, built once alongside the config. */
    private ?SmsProviderRegistry $providers = null;

    /**
     * Binds this pool instance to its shard index.
     *
     * @param string $agentIndex Pool shard index (1..SMS_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('SmsDeliveryChannelAgent requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * Routes a raw send to the in-memory pool; everything else to the delivery pipeline.
     *
     * Input B ({@see SmsSendSignalData}) is queued here without a delivery row; any other
     * payload (input A) is handed to the base intake unchanged.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($data->data instanceof SmsSendSignalData) {
            $this->enqueueRawSend($data->data);

            return;
        }

        parent::onSignalAgent($data, $source, $name);
    }

    /**
     * Pumps the delivery pipeline, then the raw-send pool.
     *
     * @throws HilosException Whatever the delivery bookkeeping or the SMS gateway raises
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
     * @return AbstractDeliveryChannel The SMS channel descriptor
     */
    protected function channel(): AbstractDeliveryChannel
    {
        return $this->channel ??= new SmsDeliveryChannel();
    }

    /**
     * Renders the notification to one line, clamps it to a segment, and wraps a fresh send.
     *
     * The title and body are already localized on the notification, so they pass through the
     * generic notification template verbatim (no locale, project default rendering).
     *
     * @param string $address Resolved recipient number
     * @param ObjectNotification $notification Notification to render and deliver
     * @return DeliveryAttempt The started SMS send
     * @throws SmsTemplateNotInCatalogException When the generic notification template is absent from the catalog
     * @throws SmsTemplateParamMissingException When the notification carries no title to head the line with
     */
    protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        $text = $this->templateRegistry()->render(
            SmsTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [
                GenericNotificationSmsTemplate::PARAM_TITLE => $notification->title,
                // external-boundary: body is a nullable column, and the template prints nothing for an empty one
                GenericNotificationSmsTemplate::PARAM_BODY => $notification->body ?? '',
            ],
        );

        return $this->buildAttempt($this->buildMessage($address, $text), microtime(true) * TimeConstants::MS_PER_SECOND);
    }

    /**
     * @return int Concurrency ceiling for the delivery and raw pools
     */
    protected function maxConcurrent(): int
    {
        return self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * Builds the SMS template registry used to render notifications and raw sends.
     *
     * A project overrides it to render through its own template catalog.
     *
     * @return SmsTemplateRegistry Registry over the framework template catalog
     */
    protected function templateRegistry(): SmsTemplateRegistry
    {
        return new SmsTemplateRegistry();
    }

    /**
     * Clamps a rendered body to one segment and wraps it as a recipient message.
     *
     * @param string $to Recipient number
     * @param string $text Rendered message body
     * @return SmsMessage Recipient message clamped to the channel's segment budget
     */
    private function buildMessage(string $to, string $text): SmsMessage
    {
        $truncation = SmsText::truncate($text, $this->maxLength());
        if ($truncation->truncated) {
            $this->logAgentInfo('sms to ' . SmsText::maskNumber($to) . ' truncated to one segment');
        }

        return new SmsMessage($to, $truncation->text);
    }

    /**
     * Builds a send attempt for a message, selecting the HTTP gateway or the stub by config.
     *
     * A config that cannot be resolved, an invalid gateway request, or a client that refuses to
     * open all settle as a permanent {@see FailedSmsDeliveryAttempt} rather than throwing out of
     * the tick loop.
     *
     * @param SmsMessage $message Recipient message to send
     * @param float $nowMs Current time in milliseconds
     * @return SmsSendAttempt The started attempt (HTTP, stub, or a permanent failure)
     */
    private function buildAttempt(SmsMessage $message, float $nowMs): SmsSendAttempt
    {
        $this->resolveConfig();
        if ($this->config === null || $this->providers === null) {
            return new FailedSmsDeliveryAttempt('sms channel disabled by invalid config');
        }

        try {
            $provider = $this->providers->providerFor($this->config);
            if ($provider instanceof DirectSmsProvider) {
                return new StubSmsDeliveryAttempt($provider, $message, $nowMs);
            }
            if ($provider instanceof HttpSmsProvider) {
                return new SmsDeliveryAttempt($provider, $message, (float)$this->config->timeoutMs, $nowMs);
            }

            return new FailedSmsDeliveryAttempt('sms provider drives neither HTTP nor direct sending');
        } catch (SmsException | AsyncHttpException | SocketException $e) {
            $this->logAgentWarning('sms send could not start: ' . $e->getMessage());

            return new FailedSmsDeliveryAttempt('sms send could not start');
        }
    }

    /**
     * Resolves the channel config and provider registry once, latching an unreadable config.
     *
     * An invalid config would otherwise throw from the tick loop on every send and crash-loop
     * the worker, losing the in-memory raw pool; instead the failure is logged once (domain
     * reason, no secrets), the config is left null, and each send settles as a permanent failure.
     */
    private function resolveConfig(): void
    {
        if ($this->configResolved) {
            return;
        }
        $this->configResolved = true;

        try {
            $channel = $this->channel();
            if ($channel instanceof SmsDeliveryChannel) {
                $this->config = SmsChannelConfig::resolve($channel);
                $this->providers = new SmsProviderRegistry();
            }
        } catch (EnvException | DatabaseException | SettingException $e) {
            $this->logAgentWarning('sms channel disabled by invalid config: ' . $e->getMessage());
        }
    }

    /**
     * @return int Resolved single-segment length budget, or the descriptor default
     */
    private function maxLength(): int
    {
        $this->resolveConfig();

        return $this->config?->maxLength ?? SmsDeliveryChannel::DEFAULT_MAX_LENGTH;
    }

    /**
     * Renders the raw send and queues it, or drops it when its template cannot be rendered.
     *
     * An unknown template key, or one asked to render without a param it needs, is a caller error
     * and is dropped with a domain-only log (masked number and key, never the params); a
     * well-formed send joins the raw pool.
     *
     * @param SmsSendSignalData $signal Raw-send payload (inline text or template)
     */
    private function enqueueRawSend(SmsSendSignalData $signal): void
    {
        try {
            $message = $this->buildRawMessage($signal);
        } catch (SmsTemplateNotInCatalogException) {
            $this->logAgentWarning(
                'raw send to ' . SmsText::maskNumber($signal->to) . " dropped: unknown template '{$signal->templateKey}'",
            );

            return;
        } catch (SmsTemplateParamMissingException $failure) {
            $this->logAgentWarning(
                'raw send to ' . SmsText::maskNumber($signal->to) . " dropped: {$failure->getMessage()}",
            );

            return;
        }

        $this->rawSends[$this->rawNextId++] = new RawSmsSend($message, $signal->templateKey);
    }

    /**
     * Builds the recipient message from a template render or the inline text.
     *
     * @param SmsSendSignalData $signal Raw-send payload
     * @return SmsMessage The message to hand a provider
     * @throws SmsTemplateNotInCatalogException When a named template is absent from the catalog
     * @throws SmsTemplateParamMissingException When a named template needs a param the payload lacks
     */
    private function buildRawMessage(SmsSendSignalData $signal): SmsMessage
    {
        // The payload invariant guarantees an inline text whenever no template names the content,
        // so it is passed through as it is: a broken invariant has to fail loudly here rather than
        // send an empty segment.
        $text = $signal->templateKey !== null
            ? $this->templateRegistry()->render($signal->templateKey, $signal->params, $signal->locale)
            : $signal->text;

        return $this->buildMessage($signal->to, $text);
    }

    /**
     * Advances each in-flight raw send and settles its outcome.
     *
     * A delivered send is dropped; a failed one is retried with backoff unless the failure is
     * permanent or the attempt ceiling is reached, in which case it is logged (masked number and
     * template key only) and dropped.
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
            $send->attempt = $this->buildAttempt($send->message, $nowMs);
        }
    }

    /**
     * @return int Number of raw sends with an attempt currently in flight
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
     * Logs a terminal raw-send failure with the masked number and template key only.
     *
     * The message text and template params are never logged: raw sends carry Auth codes.
     *
     * @param RawSmsSend $send Failed raw send
     * @param string $error Domain failure sentence
     */
    private function logRawFailure(RawSmsSend $send, string $error): void
    {
        $template = $send->templateKey ?? 'inline';
        $masked = SmsText::maskNumber($send->message->to);
        $this->logAgentWarning("raw send to {$masked} (template '{$template}') failed: {$error}");
    }
}
