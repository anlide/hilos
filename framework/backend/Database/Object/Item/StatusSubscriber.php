<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Closure;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\SqlParam;
use Hilos\HilosException;
use Hilos\Status\StatusSubscriberConfirmation;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Secret primitives join the caller's transaction with scalar sync and membership changes.
 *
 * @extends Object_<EntityStatusSubscriber>
 * @property ?int $id
 * @property string $email
 * @property int $subscribed_at
 * @property bool $confirmed
 * @property ?int $confirmed_at
 * @property ?int $confirmation_expires_at
 * @property ?string $browser_time_zone
 */
class StatusSubscriber extends Object_
{
    public const string ENTITY_CLASS = EntityStatusSubscriber::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusSubscribers::class;
    public const int TOKEN_BYTES = 32;
    public const int CONFIRMATION_TTL_MS = 86400000;

    public const string id = 'id';
    public const string email = 'email';
    public const string subscribed_at = 'subscribed_at';
    public const string confirmed = 'confirmed';
    public const string confirmed_at = 'confirmed_at';
    public const string confirmation_expires_at = 'confirmation_expires_at';
    public const string browser_time_zone = 'browser_time_zone';

    /**
     * @param string $property Non-secret scalar column
     * @return mixed Scalar value or inherited property
     * @throws DatabaseException When the inherited property is misconfigured
     * @throws PropertyNotAccessibleException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::email => $this->entity->email,
            self::subscribed_at => $this->entity->subscribed_at,
            self::confirmed => $this->entity->confirmed,
            self::confirmed_at => $this->entity->confirmed_at,
            self::confirmation_expires_at => $this->entity->confirmation_expires_at,
            self::browser_time_zone => $this->entity->browser_time_zone,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Non-secret scalar column
     * @param mixed $value Value matching the column type
     * @throws DatabaseException When the column cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::email => $this->entity->email = $value,
            self::subscribed_at => $this->entity->subscribed_at = $value,
            self::confirmed => $this->entity->confirmed = $value,
            self::confirmed_at => $this->entity->confirmed_at = $value,
            self::confirmation_expires_at => $this->entity->confirmation_expires_at = $value,
            self::browser_time_zone => $this->entity->browser_time_zone = $value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Installs the permanent subscription secret once; never exposes it as a property.
     *
     * @throws HilosException When the row or write grant is missing, or SQL fails
     * @throws RandomException When secure entropy is unavailable
     */
    public function initializeUnsubscribeToken(): void
    {
        $this->guardSecretWrite();
        Database::sql(
            'UPDATE ' . $this->entity::getDatabase()->getSqlTableName($this->entity::_table) . ' SET `unsubscribe_token` = ?'
                . ' WHERE `id` = ? AND `unsubscribe_token` IS NULL',
            SqlParamCollection::fromArray([SqlParam::string(RandomHelper::secureHex(self::TOKEN_BYTES)), SqlParam::int($this->entity->id)]),
            index: $this->entity::getDatabase()->connectionIndex,
        );
    }

    /**
     * The raw confirmation value lives only in the result destined for afterCommit mail.
     *
     * @param int $at Issue time in UTC epoch milliseconds
     * @return ?StatusSubscriberConfirmation New challenge, or null for a confirmed or deleted row
     * @throws HilosException When the moment, write grant, or persistence is invalid
     * @throws RandomException When secure entropy is unavailable
     */
    public function issueConfirmation(int $at): ?StatusSubscriberConfirmation
    {
        $this->guardSecretWrite();
        if ($at < 0 || $at > PHP_INT_MAX - self::CONFIRMATION_TTL_MS) {
            throw new ValidationException('The confirmation time is outside the supported range.');
        }
        $token = RandomHelper::secureHex(self::TOKEN_BYTES);
        $expiresAt = $at + self::CONFIRMATION_TTL_MS;
        Database::sql(
            'UPDATE ' . $this->entity::getDatabase()->getSqlTableName($this->entity::_table) . ' SET `confirmation_token_hash` = ?,'
                . ' `confirmation_expires_at` = ?'
                . ' WHERE `id` = ? AND `confirmed` = 0',
            SqlParamCollection::fromArray([
                SqlParam::string(hash('sha256', $token)), SqlParam::int($expiresAt), SqlParam::int($this->entity->id),
            ]),
            index: $this->entity::getDatabase()->connectionIndex,
        );
        if (Database::affectedRows($this->entity::getDatabase()->connectionIndex) !== 1) {
            return null;
        }
        $this->entity->confirmation_expires_at = $expiresAt;
        $this->sync();
        return new StatusSubscriberConfirmation($this->entity->id, $token);
    }

    /**
     * One conditional UPDATE arbitrates the spend, even between stale loaded copies.
     *
     * @param string $token Presented raw confirmation value
     * @param int $at Confirmation time in UTC epoch milliseconds
     * @return ?int Confirmed subscription id, or null when no live matching challenge exists
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function confirm(string $token, int $at): ?int
    {
        $this->guardSecretWrite();
        if ($at < 0) {
            throw new ValidationException('The confirmation time must be nonnegative.');
        }
        Database::sql(
            'UPDATE ' . $this->entity::getDatabase()->getSqlTableName($this->entity::_table) . ' SET `confirmed` = 1, `confirmed_at` = ?, '
                . '`confirmation_token_hash` = NULL, `confirmation_expires_at` = NULL'
                . ' WHERE `id` = ? AND `confirmation_token_hash` = ? AND `confirmed` = 0 AND `confirmation_expires_at` > ?',
            SqlParamCollection::fromArray([
                SqlParam::int($at), SqlParam::int($this->entity->id), SqlParam::string(hash('sha256', $token)), SqlParam::int($at),
            ]),
            index: $this->entity::getDatabase()->connectionIndex,
        );
        if (Database::affectedRows($this->entity::getDatabase()->connectionIndex) !== 1) {
            return null;
        }
        $this->entity->confirmed = true;
        $this->entity->confirmed_at = $at;
        $this->entity->confirmation_expires_at = null;
        $this->sync();
        return $this->entity->id;
    }

    /**
     * Gives the owner its mail-only secret at delivery time; restored rows have no usable link.
     *
     * @param Closure(string, string, ?string): void $deliver Mail operation receiving email, unsubscribe token and recipient zone
     * @throws HilosException When ownership, persistence, or the mail operation refuses the call
     */
    public function deliverMail(Closure $deliver): void
    {
        $this->guardSecretWrite(requiresTransaction: false);
        $row = Database::sql(
            'SELECT `email`, `unsubscribe_token`, `browser_time_zone` FROM ' . $this->entity::getDatabase()->getSqlTableName($this->entity::_table)
                . ' WHERE `id` = ?',
            SqlParamCollection::fromArray([SqlParam::int($this->entity->id)]),
            index: $this->entity::getDatabase()->connectionIndex,
        )->first()?->first();
        if ($row !== null && is_string($row[EntityStatusSubscriber::unsubscribe_token])) {
            $deliver($row[EntityStatusSubscriber::email], $row[EntityStatusSubscriber::unsubscribe_token],
                $row[EntityStatusSubscriber::browser_time_zone]);
        }
    }

    /** @return array<string, mixed> Scalar row without either token */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::email => $this->entity->email,
            self::subscribed_at => $this->entity->subscribed_at,
            self::confirmed => $this->entity->confirmed,
            self::confirmed_at => $this->entity->confirmed_at,
            self::confirmation_expires_at => $this->entity->confirmation_expires_at,
            self::browser_time_zone => $this->entity->browser_time_zone,
        ];
    }

    /**
     * @param bool $requiresTransaction Whether scalar changes must share a caller transaction
     * @throws HilosException When the row, transaction or its owner grant is unavailable
     */
    private function guardSecretWrite(bool $requiresTransaction = true): void
    {
        if ($this->entity->id === null) {
            throw new DatabaseException('A subscription must be persisted before using its secrets');
        }
        if ($requiresTransaction && !Database::hasOpenTransactions()) {
            throw new DatabaseException('Subscription secret writes require the caller transaction');
        }
        Database::assertCanWrite($this->entity::getDatabase()->connectionIndex);
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(), (string)$this->entity->id, $this->touchedSetKeys(...), TruthSourceOperation::Update,
        );
    }
}
