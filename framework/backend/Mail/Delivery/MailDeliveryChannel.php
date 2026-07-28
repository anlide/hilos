<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;

/**
 * MailDeliveryChannel - the email delivery channel descriptor (HIL-197).
 *
 * The first concrete delivery channel: it names the `email` channel, points the
 * dispatcher at the mail pool's deliver signal (HILOS_MAIL_DELIVER), and resolves a
 * recipient's address from the framework identities. It is the email channel's
 * extension seam — a project subclasses it to source the address differently.
 *
 * The channel is pooled: its notification-delivery intake shards by the recipient's
 * email address ({@see HilosMailer::shardKeyForAddress}), the same rule the raw-send
 * intake uses, so every message to one address — delivery or raw send — lands on the
 * same pool instance and its ordering (and any future per-address rate limit) stays
 * local to that agent. Global enablement gates only this delivery intake; Auth raw
 * sends never pass through the dispatcher.
 */
class MailDeliveryChannel extends AbstractDeliveryChannel
{
    /** The `email` channel name (registry key and delivery-row `channel` value). */
    public const string NAME = 'email';

    /**
     * @return string The `email` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return string The mail pool's notification-delivery agent signal
     */
    public function deliverSignalName(): string
    {
        return HilosSignalConstants::HILOS_MAIL_DELIVER;
    }

    /**
     * Resolves the recipient's verified email from the framework identities.
     *
     * @param int $userId Recipient user id
     * @return ?string Verified email address, or null when the recipient has none (or no DB context)
     * @throws DatabaseException When the identity lookup fails
     */
    public function resolveAddress(int $userId): ?string
    {
        return Hilos::$db?->identities->findVerifiedEmailByUser($userId);
    }

    /**
     * @return bool True: the email channel fans across an indexed agent pool
     */
    public function isPooled(): bool
    {
        return true;
    }

    /**
     * Shards by the recipient's email address so both mail intakes co-locate.
     *
     * @param int $userId Recipient user id
     * @param int $notificationId Notification id being delivered (unused; the address is the shard dimension)
     * @return ?int Positive pool shard key for the recipient address, or null when it no longer resolves
     * @throws DatabaseException When the identity lookup fails
     * @throws EnvException When MAIL_WORKER_COUNT is unreadable
     */
    public function shardKeyFor(int $userId, int $notificationId): ?int
    {
        $address = $this->resolveAddress($userId);

        return $address === null ? null : HilosMailer::shardKeyForAddress($address);
    }
}
