<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailConfigException;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
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
 * SLICE 5a wires the notification-delivery intake (input A). The raw-send intake
 * (input B, {@see HilosSignalConstants::HILOS_MAIL_SEND}) and crash recovery of pending
 * rows are added in a later slice.
 */
final class MailDeliveryChannelAgent extends AbstractDeliveryChannelAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MAIL;

    /** Input A route: the notification-delivery signal, fanned across the pool by shard key. */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_MAIL_DELIVER => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class,
        ],
    ];

    /** Fallback concurrency ceiling when MAIL_MAX_CONCURRENT is unavailable. */
    private const int DEFAULT_MAX_CONCURRENT = 4;

    /** The channel descriptor, built once and reused across ticks. */
    private ?MailDeliveryChannel $channel = null;

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
     * @throws MailBusyException When the freshly built transport is not idle (never in practice)
     * @throws EnvException When a MAIL_* transport env value is missing or has the wrong type
     * @throws MailConfigException When MAIL_SMTP_SECURITY is not a recognized mode
     */
    protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        $content = $this->templateRegistry()->render(
            MailTemplateCatalogConstants::NOTIFICATION_GENERIC,
            [
                GenericNotificationMailTemplate::PARAM_TITLE => $notification->title,
                GenericNotificationMailTemplate::PARAM_BODY => $notification->body ?? '',
            ],
        );

        return new MailDeliveryAttempt(
            $this->createTransport(),
            new EmailMessage($address, $content->subject, $content->text, html: $content->html),
            microtime(true) * 1000,
        );
    }

    /**
     * @return int Concurrency ceiling from MAIL_MAX_CONCURRENT, or the default
     * @throws EnvException When MAIL_MAX_CONCURRENT is present but not an int
     */
    protected function maxConcurrent(): int
    {
        return max(1, Hilos::$env?->int(EnvConstants::MAIL_MAX_CONCURRENT) ?? self::DEFAULT_MAX_CONCURRENT);
    }

    /**
     * Builds the configured mail transport for one send.
     *
     * The transport seam: tests override it to inject a fake transport instead of opening
     * a real SMTP or file send.
     *
     * @return MailTransportInterface A fresh transport built from the MAIL_* config
     * @throws EnvException When a MAIL_* env value is missing or has the wrong type
     * @throws MailConfigException When MAIL_SMTP_SECURITY is not a recognized mode
     */
    protected function createTransport(): MailTransportInterface
    {
        return (new MailTransportFactory())->create(MailTransportConfig::fromEnv());
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
}
