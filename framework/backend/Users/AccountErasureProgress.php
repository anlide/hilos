<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;

/** The fixed composition and outstanding waits of a begun, open account erasure. */
final readonly class AccountErasureProgress
{
    public const string startedAt = 'startedAt';
    public const string parts = 'parts';
    public const string waits = 'waits';
    public const string participant = 'participant';
    public const string owner = 'owner';
    public const string userId = 'userId';
    public const string done = 'done';

    /**
     * @param int $startedAt Request's start moment in milliseconds
     * @param list<array{participant: string, owner: string, userId: int, done: bool}> $parts Expected owners in assignment id order
     * @param list<array{participant: string, userId: int}> $waits Participants whose owner is not yet expected
     */
    public function __construct(public int $startedAt, public array $parts, public array $waits)
    {
    }

    /**
     * @return array{startedAt: int, parts: list<array{participant: string, owner: string, userId: int, done: bool}>,
     *     waits: list<array{participant: string, userId: int}>} Erasure progress as it travels
     */
    public function toArray(): array
    {
        return [
            self::startedAt => $this->startedAt,
            self::parts => $this->parts,
            self::waits => $this->waits,
        ];
    }

    /** @return array<string, WireField> Progress fields and their origins for the admin view mode */
    public static function wireFields(): array
    {
        return [
            self::startedAt => WireField::notPersonal(),
            self::parts => WireField::each([
                self::participant => WireField::notPersonal(),
                self::owner => WireField::notPersonal(),
                self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
                self::done => WireField::notPersonal(),
            ]),
            self::waits => WireField::each([
                self::participant => WireField::notPersonal(),
                self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
            ]),
        ];
    }
}
