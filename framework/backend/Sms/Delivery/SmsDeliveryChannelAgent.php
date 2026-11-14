<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\Exception\SmsTemplateNotInCatalogException;
use Hilos\Sms\Exception\SmsTemplateParamMissingException;
use Hilos\Sms\Template\GenericNotificationSmsTemplate;
use Hilos\Sms\Template\SmsTemplateCatalogConstants;

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
 * a restart - they have no durable record. Pending input-A rows are picked up by the base
 * pipeline when the shard starts or a freeze lets it go (HIL-1135).
 */
class SmsDeliveryChannelAgent extends AbstractDeliveryChannelAgent
{
    use SmsSendPoolTrait;

    public const string AGENT_TYPE = HilosAgentType::HILOS_SMS;

    public const string ACCOUNT_OPERATION_SIGNAL = HilosSignalConstants::HILOS_SMS_ACCOUNT_OPERATION_STEP;

    /**
     * Pool routes: intakes and retry requests use the recipient's shard key; account operation
     * steps carry their saved owner index directly.
     *  - HILOS_SMS_DELIVER (input A) carries a notification delivery;
     *  - HILOS_SMS_SEND (input B) carries a raw send;
     *  - HILOS_SMS_DELIVERY_RETRY carries the library's request to take a failed delivery back (HIL-1910).
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SMS_ACCOUNT_OPERATION_STEP => [
            AgentSignalConfigKey::INDEX_FIELD => AccountOperationAskSignalData::ownerIndex,
            AgentSignalConfigKey::DTO => AccountOperationAskSignalData::class,
        ],
        HilosSignalConstants::HILOS_SMS_DELIVER => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class,
        ],
        HilosSignalConstants::HILOS_SMS_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => SmsSendSignalData::shardKey,
            AgentSignalConfigKey::DTO => SmsSendSignalData::class,
        ],
        HilosSignalConstants::HILOS_SMS_DELIVERY_RETRY => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliveryRetrySignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class,
        ],
    ];

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
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Routed agent-signal name
     * @throws HilosException When fresh durable delivery coordinates cannot be read
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        if ($data->data instanceof SmsSendSignalData) {
            $this->enqueueRawSend($data->data);

            return;
        }

        parent::onSignalAgent($data, $sender, $name);
    }

    /**
     * Pumps the delivery pipeline, then the independent raw pool even when durable ownership fails.
     *
     * @throws HilosException When durable configuration, delivery bookkeeping or a transport fails
     */
    public function onTick(): void
    {
        try {
            parent::onTick();
        } finally {
            $this->tickRawSends(microtime(true) * TimeConstants::MS_PER_SECOND);
        }
    }

    /**
     * Abandons every in-flight raw send, then lets the base pipeline shut down.
     */
    public function onStop(): void
    {
        $this->closeRawSends();

        parent::onStop();
    }

    /**
     * Renders the notification to one line, clamps it to a segment, and wraps a fresh send.
     *
     * The title and body are already localized on the notification, so they pass through the
     * generic notification template verbatim (no locale, project default rendering). The agent
     * resolves no locale of its own: the language of an outgoing message is decided where the
     * message is ordered, by the rule on MailTemplate
     * (framework/backend/Mail/Template/MailTemplate.php).
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
}
