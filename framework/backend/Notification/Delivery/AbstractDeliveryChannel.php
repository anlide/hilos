<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;

/**
 * AbstractDeliveryChannel - the descriptor a channel leaf implements to be dispatchable (HIL-196).
 *
 * One concrete descriptor per channel (email 197, sms 285, push 199, telegram
 * 198) declares the channel's identity and the two seams the dispatcher needs: how
 * to resolve a recipient's address for the channel, and which agent signal carries
 * the delivery handoff. Registered by name in a project's
 * {@see DeliveryChannelRegistry}. A channel is used for a notification only when it
 * is globally enabled ({@see enabledSettingKey()}) and {@see resolveAddress()}
 * yields an address.
 *
 * A channel is a singleton by default; a high-volume channel overrides
 * {@see isPooled()} + {@see indexField()} to fan across an indexed agent pool, and
 * {@see shardKeyFor()} then picks the pool shard (the recipient id by default, so a
 * recipient's deliveries stay on one instance).
 */
abstract class AbstractDeliveryChannel
{
    /**
     * The channel name (its registry key and the `channel` column value).
     *
     * @return string Stable channel name (e.g. email, telegram)
     */
    abstract public function name(): string;

    /**
     * The agent signal that hands one delivery to this channel's agent.
     *
     * The channel leaf declares the matching route in its agent's AGENT_SIGNALS
     * (with {@see \Hilos\Core\Agent\Config\AgentSignalConfigKey::INDEX_FIELD} set to
     * `shardKey` for a pooled channel); the dispatcher only queues the signal by
     * this name.
     *
     * @return string Delivery agent-signal name
     */
    abstract public function deliverSignalName(): string;

    /**
     * Resolves the recipient's address for this channel, or null when unreachable.
     *
     * The address seam: email/sms read the recipient's Identities; telegram/push
     * read their own address stores (arriving with 198/199). A null result skips the
     * channel for this recipient (no delivery row, no signal).
     *
     * @param int $userId Recipient user id
     * @return ?string Channel address, or null when the recipient has none
     */
    abstract public function resolveAddress(int $userId): ?string;

    /**
     * Whether this channel fans across an indexed agent pool rather than a singleton.
     *
     * @return bool True for a pooled (indexed) channel
     */
    public function isPooled(): bool
    {
        return false;
    }

    /**
     * The payload field an indexed channel routes on, or null for a singleton.
     *
     * @return ?string Index field name (`shardKey`) for a pooled channel, else null
     */
    public function indexField(): ?string
    {
        return $this->isPooled() ? NotificationDeliverSignalData::shardKey : null;
    }

    /**
     * The pool shard key for a delivery, or null for a singleton channel.
     *
     * Defaults to the recipient id so a recipient's deliveries stay on one pool
     * instance (in-order, cache-friendly); a channel may override to shard by
     * another dimension.
     *
     * @param int $userId Recipient user id
     * @param int $notificationId Notification id being delivered
     * @return ?int Positive shard key for a pooled channel, or null for a singleton
     */
    public function shardKeyFor(int $userId, int $notificationId): ?int
    {
        return $this->isPooled() ? $userId : null;
    }

    /**
     * The global-enablement setting key for this channel.
     *
     * @return string Setting key `notifications.channel.<name>.enabled`
     */
    public function enabledSettingKey(): string
    {
        return DeliveryChannelSettings::enabledKey($this->name());
    }
}
