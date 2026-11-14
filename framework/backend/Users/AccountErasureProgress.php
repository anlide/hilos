<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;

/** The fixed composition, outstanding waits and standing final refusals of a begun, open account erasure. */
final readonly class AccountErasureProgress
{
    public const string startedAt = 'startedAt';
    public const string parts = 'parts';
    public const string waits = 'waits';
    public const string refusals = 'refusals';
    public const string participant = 'participant';
    public const string owner = 'owner';
    public const string userId = 'userId';
    public const string table = 'table';
    public const string done = 'done';

    /**
     * @param int $startedAt Request's start moment in milliseconds
     * @param list<array{participant: string, owner: string, userId: int, done: bool}> $parts Expected owners in assignment id order
     * @param list<array{participant: string, userId: int}> $waits Participants whose owner is not yet expected
     * @param list<array{userId: int, table: ?string}> $refusals Safe reasons of refused finals in circle order
     */
    public function __construct(public int $startedAt, public array $parts, public array $waits, public array $refusals)
    {
    }

    /**
     * @return array{startedAt: int, parts: list<array{participant: string, owner: string, userId: int, done: bool}>,
     *     waits: list<array{participant: string, userId: int}>, refusals: list<array{userId: int, table: ?string}>}
     *     Erasure progress as it travels
     */
    public function toArray(): array
    {
        return [
            self::startedAt => $this->startedAt,
            self::parts => $this->parts,
            self::waits => $this->waits,
            self::refusals => $this->refusals,
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
            self::refusals => WireField::each([
                self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
                self::table => WireField::notPersonal(),
            ]),
        ];
    }
}
