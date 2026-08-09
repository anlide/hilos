<?php

declare(strict_types=1);

namespace Hilos\Push\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Push\Exception\PushException;
use Hilos\Push\PushChannelConfig;
use Hilos\Push\WebPushRequestFactory;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Socket\SocketException;

/**
 * PushDeliveryChannelAgent - the sharded web-push delivery agent (HIL-199).
 *
 * The concrete delivery agent for the `push` channel, mirroring
 * {@see MailDeliveryChannelAgent} and
 * {@see SmsDeliveryChannelAgent}: it owns one shard of the `hilos_push` pool and
 * turns each dispatched notification into a fan-out of non-blocking web-push sends, one per device the
 * recipient subscribed. The base pipeline drives intake, the concurrency pool, bounded retries, and the
 * one-row-per-channel delivery bookkeeping; this leaf supplies the channel descriptor
 * ({@see PushDeliveryChannel}) and wraps one notification as a {@see PushDeliveryAttempt} over the
 * recipient's subscriptions, rendering the notification into the JSON payload the service worker reads.
 *
 * Push differs from mail/sms in two ways. It is multi-address: {@see resolveAddress()} answers presence,
 * not a routable value, and {@see createAttempt()} re-resolves the recipient's endpoints and sends to
 * each. And it has no raw-send intake (no Auth-code path), so the pool has a single route,
 * {@see HilosSignalConstants::HILOS_PUSH_DELIVER}, and no in-memory raw pool. The VAPID config is
 * resolved once and latched; an invalid or absent config settles each send as a permanent failure
 * rather than throwing out of the tick loop. Crash recovery of pending rows in onStart is deferred to a
 * later slice, as for the other channels.
 */
class PushDeliveryChannelAgent extends AbstractDeliveryChannelAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_PUSH;

    /**
     * Pool route: the notification-delivery signal, fanned across the pool by the recipient's shard key.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_PUSH_DELIVER => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class,
        ],
    ];

    /** Payload key: the notification title the service worker shows. */
    public const string PAYLOAD_KEY_TITLE = 'title';

    /** Payload key: the notification body the service worker shows. */
    public const string PAYLOAD_KEY_BODY = 'body';

    /** Payload key: the structured data the service worker attaches for the click handler. */
    public const string PAYLOAD_KEY_DATA = 'data';

    /** Data key: the delivered notification's id, for the service worker click handler. */
    public const string DATA_KEY_NOTIFICATION_ID = 'notificationId';

    /** Data key: the notification type, for the service worker click handler. */
    public const string DATA_KEY_TYPE = 'type';

    /** The channel descriptor, built once and reused across ticks. */
    private ?PushDeliveryChannel $channel = null;

    /** Whether the VAPID config has been resolved yet. */
    private bool $configResolved = false;

    /** Resolved VAPID config, or null when it could not be read (sends then fail permanently). */
    private ?PushChannelConfig $config = null;

    /** Request factory, built once alongside the config. */
    private ?WebPushRequestFactory $requestFactory = null;

    /**
     * Binds this pool instance to its shard index.
     *
     * @param string $agentIndex Pool shard index (1..PUSH_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('PushDeliveryChannelAgent requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * @return AbstractDeliveryChannel The push channel descriptor
     */
    protected function channel(): AbstractDeliveryChannel
    {
        return $this->channel ??= new PushDeliveryChannel();
    }

    /**
     * Renders the notification and fans one send out across the recipient's device endpoints.
     *
     * The address is a presence marker, not a routable value, so it is ignored; the recipient's
     * endpoints are re-resolved here. An endpoint whose request cannot be signed/encrypted or started
     * becomes a pre-settled failed send rather than throwing, so one bad device never sinks the others.
     *
     * @param string $address Presence marker from {@see PushDeliveryChannel::resolveAddress()} (unused)
     * @param ObjectNotification $notification Notification to render and deliver
     * @return DeliveryAttempt The started multi-endpoint push attempt
     * @throws DatabaseException When the subscription lookup fails
     */
    protected function createAttempt(string $address, ObjectNotification $notification): DeliveryAttempt
    {
        $this->resolveConfig();
        $payload = $this->buildPayload($notification);

        $sends = [];
        foreach ($this->subscriptions()->forUser($notification->userId ?? 0) as $subscription) {
            $sends[] = $this->openSend($subscription, $payload);
        }

        return new PushDeliveryAttempt($sends);
    }

    /**
     * Builds the web-push request factory used to sign and encrypt sends.
     *
     * A project overrides it to inject a fake factory in tests.
     *
     * @return WebPushRequestFactory The request factory
     */
    protected function requestFactory(): WebPushRequestFactory
    {
        return $this->requestFactory ??= new WebPushRequestFactory();
    }

    /**
     * Opens one endpoint's non-blocking send, or a pre-settled failure when it cannot start.
     *
     * @param ObjectPushSubscription $subscription Target device subscription
     * @param string $payload Rendered notification payload
     * @return PushEndpointSend The started send, or a pre-settled failed send
     */
    private function openSend(ObjectPushSubscription $subscription, string $payload): PushEndpointSend
    {
        if ($this->config === null) {
            return PushEndpointSend::failed($subscription->endpoint, 'push channel disabled by invalid config');
        }

        try {
            $request = $this->requestFactory()->build($this->config, $subscription, $payload);
            $client = new AsyncHttpClient($request->host, $request->port, $request->path, $request->useTls);
            $client->timeout = $this->config->timeoutMs;
            $client->setRequestOptions(HttpConstants::METHOD_POST, $request->path, $request->body, $request->headers);
            $client->startNewRequest(microtime(true) * 1000);

            return new PushEndpointSend($subscription->endpoint, $client);
        } catch (PushException | AsyncHttpException | SocketException $e) {
            return PushEndpointSend::failed($subscription->endpoint, 'push send could not start: ' . $e->getMessage());
        }
    }

    /**
     * Renders the notification into the JSON payload the service worker reads.
     *
     * The title and body are already localized on the notification; the structured `data` is carried
     * through for the click handler with the notification id and type folded in.
     *
     * @param ObjectNotification $notification Notification to render
     * @return string JSON payload document
     */
    private function buildPayload(ObjectNotification $notification): string
    {
        $data = $notification->decodedData() ?? [];
        $data[self::DATA_KEY_NOTIFICATION_ID] = $notification->id;
        $data[self::DATA_KEY_TYPE] = $notification->type;

        return json_encode([
            self::PAYLOAD_KEY_TITLE => $notification->title,
            // external-boundary: body is a nullable column, and a push with no body carries an empty one
            self::PAYLOAD_KEY_BODY => $notification->body ?? '',
            self::PAYLOAD_KEY_DATA => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Resolves the VAPID config and request factory once, latching an unreadable config.
     *
     * An invalid config would otherwise throw from the tick loop on every send and crash-loop the
     * worker; instead the failure is logged once (domain reason, no secrets), the config is left null,
     * and each send settles as a permanent failure through {@see openSend()}.
     */
    private function resolveConfig(): void
    {
        if ($this->configResolved) {
            return;
        }
        $this->configResolved = true;

        try {
            $channel = $this->channel();
            if ($channel instanceof PushDeliveryChannel) {
                $this->config = PushChannelConfig::resolve($channel);
                $this->requestFactory = new WebPushRequestFactory();
            }
        } catch (EnvException | DatabaseException | SettingException $e) {
            $this->logAgentWarning('push channel disabled by invalid config: ' . $e->getMessage());
        }
    }

    /**
     * Resolves the framework-owned push-subscriptions object collection.
     *
     * @return ObjectPushSubscriptions Subscription persistence primitives
     * @throws DatabaseException When the subscription collection is not configured
     */
    private function subscriptions(): ObjectPushSubscriptions
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::pushSubscriptions);
        if (!$collection instanceof ObjectPushSubscriptions) {
            throw new DatabaseException('Push subscriptions object collection is not configured');
        }

        return $collection;
    }
}
