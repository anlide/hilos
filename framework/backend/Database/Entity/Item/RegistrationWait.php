<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\RegistrationWaits as EntityRegistrationWaits;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\PhpType;
use Hilos\Runtime\State\Item\RegistrationWaiter;

/**
 * RegistrationWait Entity - represents hilos_registration_wait table row.
 *
 * Durable memory of a registration that was started and not finished (HIL-486):
 * one row is one browser SESSION waiting on one identifier's code. The step is
 * therefore served from the server on the handshake, which is what lets a reloaded
 * tab, a second tab and another device all resume the code screen instead of the
 * empty identifier field.
 *
 * Separate from {@see EntityRegistrationReservation} because the two answer
 * different questions: the reservation holds the ADDRESS and there is one per
 * address, while the sessions watching that address are several. Uniqueness sits
 * on the session rather than on the pair - a person runs one registration at a
 * time, and a new one evicts the previous row of that session.
 *
 * Not to be confused with {@see RegistrationWaiter}, the runtime item: a waiter is
 * a live SOCKET on this node and dies with it, a wait is the session's durable
 * memory and outlives both the socket and the process.
 *
 * `created_at` is written by the database and never read back, so it stays out of
 * the ORM mapping; nothing in the flow asks how old a wait is, only whose it is.
 *
 * @method static EntityRegistrationWaits get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityRegistrationWaits getAll()
 */
final class RegistrationWait extends Entity
{
    public const string id = 'id';
    public const string session_token = 'session_token';
    public const string identifier = 'identifier';

    public const string _table = 'hilos_registration_wait';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::session_token,
        self::identifier,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::session_token => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
    ];

    public const array _indexes = [
        'uk_registration_wait_session' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::session_token],
        ],
        'idx_registration_wait_identifier' => [Entity::INDEX_COLUMNS => [self::identifier]],
    ];

    public ?int $id = null;
    public string $session_token;
    public string $identifier;
}
