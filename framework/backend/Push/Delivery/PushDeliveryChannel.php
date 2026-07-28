<?php

declare(strict_types=1);

namespace Hilos\Push\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Push\PushChannelConfig;

/**
 * PushDeliveryChannel - the web-push delivery channel descriptor (HIL-199).
 *
 * The third concrete delivery channel (after email 197 and sms 285): it names the `push`
 * channel, points the dispatcher at the push pool's deliver signal (HILOS_PUSH_DELIVER),
 * and answers reachability from the recipient's per-device subscriptions
 * ({@see \Hilos\Database\Object\Collection\PushSubscriptions}). It is the push channel's
 * extension seam - a project subclasses it to source subscriptions differently.
 *
 * Push is inherently multi-address: one recipient has many device endpoints, not a single
 * address. So {@see resolveAddress()} answers presence rather than a routable value - a
 * non-null marker when the recipient has at least one subscription, null otherwise - and
 * the channel's delivery agent (HIL-199 later slice) re-resolves and sends to every
 * endpoint. The channel is pooled: unlike mail/sms it has no raw-send intake to co-locate
 * with, so it takes the framework default shard dimension (the recipient id) narrowed to
 * the push pool range.
 *
 * VAPID config is hybrid (HIL-200 model): the public key is a settings-overridable, env-backed
 * operational field (it is served to the frontend so the browser can subscribe), while the
 * private key is an env-only secret, never editable and never sent to the browser. The pair
 * is generated once and kept stable - regenerating it invalidates every existing subscription.
 */
class PushDeliveryChannel extends AbstractDeliveryChannel
{
    /** The `push` channel name (registry key and delivery-row `channel` value). */
    public const string NAME = 'push';

    /** Config field keys (fields-table row keys and settings sub-keys). */
    public const string FIELD_VAPID_PUBLIC = 'vapid_public';
    public const string FIELD_VAPID_PRIVATE = 'vapid_private';

    /**
     * @return string The `push` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return string The `webpush` transport name shown in the admin channels hub (HIL-200)
     */
    public function driver(): string
    {
        return 'webpush';
    }

    /**
     * @return string The push pool's notification-delivery agent signal
     */
    public function deliverSignalName(): string
    {
        return HilosSignalConstants::HILOS_PUSH_DELIVER;
    }

    /**
     * Answers push reachability from the recipient's device subscriptions.
     *
     * Push has no single routable address, so the result is a presence marker (the
     * subscription count) the dispatcher gates on, not a value it stores - the delivery
     * agent re-resolves the actual endpoints. Null when the recipient has no subscription
     * (or no DB context, or the hilos_push_subscription table is not activated).
     *
     * @param int $userId Recipient user id
     * @return ?string The subscription count as a presence marker, or null when the recipient is unreachable
     * @throws DatabaseException When the subscription lookup fails
     */
    public function resolveAddress(int $userId): ?string
    {
        $subscriptions = Hilos::$db?->getObjectCollection(HilosDbContext::pushSubscriptions);
        if (!$subscriptions instanceof ObjectPushSubscriptions) {
            return null;
        }

        $endpoints = $subscriptions->forUser($userId);

        return $endpoints === [] ? null : (string) count($endpoints);
    }

    /**
     * @return bool True: the push channel fans across an indexed agent pool
     */
    public function isPooled(): bool
    {
        return true;
    }

    /**
     * Shards by the recipient id so a recipient's device deliveries stay on one pool instance.
     *
     * There is no raw-send intake to co-locate with (unlike mail/sms), so the shard
     * dimension is simply the recipient id, narrowed to the push pool range.
     *
     * @param int $userId Recipient user id
     * @param int $notificationId Notification id being delivered (unused; the recipient is the shard dimension)
     * @return ?int Positive pool shard key in the range 1..PUSH_WORKER_COUNT
     */
    public function shardKeyFor(int $userId, int $notificationId): ?int
    {
        $workerCount = max(1, Hilos::$env?->int(EnvConstants::PUSH_WORKER_COUNT) ?? 1);

        return 1 + ($userId % $workerCount);
    }

    /**
     * The push channel's config fields for the admin page: the VAPID key pair.
     *
     * The public key is operational (settings-overridable, env-backed) because it is served
     * to the frontend; the private key is a secret: env-only, never editable, never sent to
     * the browser.
     *
     * @return list<ChannelConfigField> The push channel's config field descriptors
     */
    public function configFields(): array
    {
        return [
            new ChannelConfigField(self::FIELD_VAPID_PUBLIC, 'VAPID public key', SettingsCatalogConstants::TYPE_STRING, false, EnvConstants::VAPID_PUBLIC),
            new ChannelConfigField(self::FIELD_VAPID_PRIVATE, 'VAPID private key', SettingsCatalogConstants::TYPE_STRING, true, EnvConstants::VAPID_PRIVATE),
        ];
    }

    /**
     * Exposes the VAPID public key so the browser can subscribe (HIL-199).
     *
     * The profile push toggle calls `pushManager.subscribe` with this key as the
     * application-server key, and the delivery agent signs every send with the
     * matching private key - so the browser must subscribe with exactly this
     * value. Only the public key is exposed; the private key stays an env-only
     * secret. Resolution failures degrade to none (like {@see resolveAddress()}
     * returning null without DB), so the frontend shows push as unavailable
     * rather than surfacing a config error.
     *
     * @return array<string, string> The VAPID public key keyed by its field, or empty when unconfigured
     */
    public function frontendSubscribeConfig(): array
    {
        try {
            $config = PushChannelConfig::resolve($this);
        } catch (DatabaseException | SettingException | EnvException) {
            return [];
        }

        return $config->publicKey === '' ? [] : [self::FIELD_VAPID_PUBLIC => $config->publicKey];
    }
}
