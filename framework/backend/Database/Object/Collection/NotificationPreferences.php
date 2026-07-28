<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\NotificationPreferences as EntityNotificationPreferences;
use Hilos\Database\Entity\Item\NotificationPreference as EntityNotificationPreference;
use Hilos\Database\Object\Item\NotificationPreference as ObjectNotificationPreference;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * NotificationPreferences object collection - the per-user channel-preference accessor (HIL-485).
 *
 * Persistence primitives of the sparse opt-out model: a row exists only for a
 * (user, channel) pair the recipient muted, so "no row" means the channel is
 * allowed. {@see setChannel()} is the write seam — enabling a channel deletes any
 * muted row (return to default), muting one upserts a row; {@see isAllowed()} is
 * the read the {@see \Hilos\Notification\Delivery\NotificationDispatcher} consults
 * per channel at emit time; {@see mutedChannels()} feeds the profile section's
 * per-channel toggle state. {@see deleteForUser()} clears a user's rows on account
 * deletion (best-effort, soft ref).
 *
 * @extends Objects<ObjectNotificationPreference>
 * @method ObjectNotificationPreference|null current()
 * @method ObjectNotificationPreference|null first()
 * @method ObjectNotificationPreference|null last()
 * @method ObjectNotificationPreference|null get(int|string $key)
 * @method ObjectNotificationPreference|null offsetGet(mixed $offset)
 */
final class NotificationPreferences extends Objects
{
    public const string OBJECT_CLASS = ObjectNotificationPreference::class;
    public const string ENTITY_COLLECTION_CLASS = EntityNotificationPreferences::class;
    public const string COLLECTION_KEY = HilosDbContext::notificationPreferences;

    /**
     * Applies a recipient's opt in/out for one channel (HIL-485).
     *
     * Sparse write: enabling deletes any muted row (returning the channel to the
     * "allowed" default) so `enabled = true` is never stored; muting upserts a
     * single muted row. Muting an already-muted channel — or enabling an already-
     * allowed one — is an idempotent no-op.
     *
     * @param int $userId Recipient user id
     * @param string $channel Channel name (from the delivery channel registry, HIL-196)
     * @param bool $enabled True to allow the channel (delete the row), false to mute it (upsert)
     * @throws EmptyValueException When the channel name is empty
     * @throws DatabaseException When the delete or insert query fails
     */
    public function setChannel(int $userId, string $channel, bool $enabled): void
    {
        if ($channel === '') {
            throw new EmptyValueException('Notification preference channel is required');
        }

        $existing = $this->find($userId, $channel);

        if ($enabled) {
            if ($existing !== null) {
                $existing->delete();
                if ($existing->id !== null) {
                    unset($this->objects[$existing->id]);
                }
            }

            return;
        }

        if ($existing !== null) {
            return;
        }

        $now = TimeHelper::getSqlDateTime();
        $preference = ObjectNotificationPreference::create();
        $preference->userId = $userId;
        $preference->channel = $channel;
        $preference->enabled = false;
        $preference->createdAt = $now;
        $preference->updatedAt = $now;
        $preference->sync();

        if ($preference->id !== null) {
            $this->objects[$preference->id] = $preference;
        }
    }

    /**
     * Whether a recipient allows a channel (no muted row for the pair).
     *
     * @param int $userId Recipient user id
     * @param string $channel Channel name
     * @return bool True when the channel is not muted for the recipient
     * @throws DatabaseException When the lookup query fails
     */
    public function isAllowed(int $userId, string $channel): bool
    {
        return self::channelAllowed($this->mutedChannels($userId), $channel);
    }

    /**
     * Lists the channels a recipient has muted.
     *
     * A project registers the preference collection unconditionally
     * ({@see HilosDbContext::configure()}) but may not have activated the
     * hilos_notification_preference table (no migration). The sparse model then
     * treats every channel as allowed, so the read degrades to an empty muted set
     * instead of hitting a missing table — keeping the profile subscription and the
     * emit-time dispatch inert until a project activates the table.
     *
     * @param int $userId Recipient user id
     * @return list<string> Muted channel names (empty when the recipient muted nothing, or the table is not activated)
     * @throws DatabaseException When the lookup query fails
     */
    public function mutedChannels(int $userId): array
    {
        if (Schema::getTable(EntityNotificationPreference::_table) === null) {
            return [];
        }

        $entities = EntityNotificationPreference::get([EntityNotificationPreference::user_id => $userId]);

        $muted = [];
        foreach ($entities as $entity) {
            if (!$entity->enabled && $entity->channel !== '') {
                $muted[] = $entity->channel;
            }
        }

        return $muted;
    }

    /**
     * Removes every preference row of a recipient (account-deletion cleanup).
     *
     * Best-effort: `user_id` is a soft ref with no cascade, so the deleting flow
     * clears the recipient's rows explicitly.
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     */
    public function deleteForUser(int $userId): void
    {
        $entities = EntityNotificationPreference::get([EntityNotificationPreference::user_id => $userId]);
        foreach ($entities as $entity) {
            $id = $entity->id;
            $preference = $id !== null && isset($this->objects[$id])
                ? $this->objects[$id]
                : ObjectNotificationPreference::fromEntity($entity);
            $preference->delete();
            if ($id !== null) {
                unset($this->objects[$id]);
            }
        }
    }

    /**
     * Pure sparse-resolve decision: a channel is allowed unless it is in the muted set.
     *
     * The DB-free core of {@see isAllowed()} — no muted row means allowed, a muted
     * row cuts the channel.
     *
     * @param list<string> $mutedChannels The recipient's muted channels
     * @param string $channel Channel name to test
     * @return bool True when the channel is allowed (not muted)
     */
    public static function channelAllowed(array $mutedChannels, string $channel): bool
    {
        return !in_array($channel, $mutedChannels, true);
    }

    /**
     * Loads a recipient's muted row for one channel, if present.
     *
     * @param int $userId Recipient user id
     * @param string $channel Channel name
     * @return ?ObjectNotificationPreference The muted preference row, or null when the channel is allowed
     * @throws DatabaseException When the lookup query fails
     */
    private function find(int $userId, string $channel): ?ObjectNotificationPreference
    {
        $entity = EntityNotificationPreference::get([
            EntityNotificationPreference::user_id => $userId,
            EntityNotificationPreference::channel => $channel,
        ])->first();

        if ($entity === null || $entity->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->objects[$entity->id] = ObjectNotificationPreference::fromEntity($entity);
        }

        return $this->objects[$entity->id];
    }
}
