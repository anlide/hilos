<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
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
use Hilos\Sms\Template\SmsTemplateRegistry;
use Hilos\Socket\SocketException;

/**
 * Shared SMS rendering, transport configuration and instance-local raw-send pool.
 *
 * Handles, queued messages and backoff belong to one agent's current work and are lost on restart.
 * The consuming agent declares its settings read and owns its lifecycle and signal intake.
 */
trait SmsSendPoolTrait
{
    /** Concurrency ceiling for both the delivery pool and the raw pool. */
    private const int DEFAULT_MAX_CONCURRENT = 4;

    /** Attempt ceiling shared by durable and raw sends. */
    private const int DEFAULT_MAX_ATTEMPTS = 3;

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
     * @return AbstractDeliveryChannel The SMS channel descriptor
     */
    protected function channel(): AbstractDeliveryChannel
    {
        return $this->channel ??= new SmsDeliveryChannel();
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
    protected function buildAttempt(SmsMessage $message, float $nowMs): SmsSendAttempt
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
     * Exponential retry backoff for the next raw attempt.
     *
     * @param int $attempts Attempts made so far
     * @return float Backoff in milliseconds before the next attempt
     */
    protected function rawBackoffMs(int $attempts): float
    {
        return self::RAW_RETRY_BACKOFF_BASE_MS * (2 ** max(0, $attempts - 1));
    }

    /** @return int Attempt ceiling for the delivery and raw pools */
    protected function maxAttempts(): int
    {
        return self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Advances in-flight raw sends, then starts queued sends whose backoff has elapsed.
     *
     * @param float $nowMs Current time in milliseconds
     * @throws HilosException When an in-flight SMS transport cannot advance
     */
    protected function tickRawSends(float $nowMs): void
    {
        $this->pumpRawInFlight($nowMs);
        $this->startRawQueued($nowMs);
    }

    /** Abandons raw sends and releases every in-flight transport. */
    protected function closeRawSends(): void
    {
        foreach ($this->rawSends as $send) {
            $send->attempt?->close();
        }
        $this->rawSends = [];
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
    protected function enqueueRawSend(SmsSendSignalData $signal): void
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

        $this->rawSends[$this->rawNextId++] = new RawSmsSend($message, $signal->templateKey, $signal->progressTicket);
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
     * @throws HilosException When an in-flight SMS transport cannot advance
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
                $this->reportCodeSendStep($send, HilosCodeSendAttempt::STATE_SENT, null);
                unset($this->rawSends[$id]);
                continue;
            }

            $permanent = $attempt->isPermanentFailure();
            $error = $attempt->errorDetail() ?? 'delivery failed';
            $attempt->close();
            $send->attempt = null;

            if ($permanent || $send->attempts >= $this->maxAttempts()) {
                $this->logRawFailure($send, $error);
                $this->reportCodeSendStep($send, HilosCodeSendAttempt::STATE_FAILED, $error);
                unset($this->rawSends[$id]);
                continue;
            }
            $send->nextAttemptMs = $nowMs + $this->rawBackoffMs($send->attempts);
            $this->reportCodeSendStep($send, HilosCodeSendAttempt::STATE_QUEUED, null);
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
            $this->reportCodeSendStep($send, HilosCodeSendAttempt::STATE_SENDING, null);
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

    /**
     * Reports a watched raw send's transport state to the session holder.
     *
     * @param RawSmsSend $send Raw send whose state changed
     * @param string $state New send state
     * @param ?string $detail Provider refusal sentence, or null
     */
    private function reportCodeSendStep(RawSmsSend $send, string $state, ?string $detail): void
    {
        if ($send->progressTicket === null) {
            return;
        }

        try {
            $this->sendToAgent(
                HilosSignalConstants::HILOS_CODE_SEND_STEP,
                CodeSendStepSignalData::step($send->progressTicket, $state, $detail),
            );
        } catch (InvalidArgumentException $failure) {
            $this->logAgentWarning('code send step could not be reported: ' . $failure->getMessage());
        }
    }
}
