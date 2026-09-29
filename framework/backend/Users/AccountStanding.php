<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Legal\LegalDocumentStanding;

/**
 * The standing of one account: three independent facts and the one of them that is shown (HIL-945).
 *
 * A block and a scheduled deletion are stored; a freeze is not - it is what the acceptance records
 * and the revision catalog say today under the installation's refusal setting. None of the three
 * replaces another, so the verdict names each of them and picks the one to show separately
 * ({@see AccountStandingKind}). Composed in exactly one place, {@see AccountStandingResolver},
 * and read by the session frame, the page guard and the admin card alike.
 *
 * It travels in one shape everywhere ({@see self::toArray()}) and nobody sends it back, so there is
 * no reader from the wire.
 */
final readonly class AccountStanding
{
    public const string shown = 'shown';
    public const string blocked = 'blocked';
    public const string frozen = 'frozen';
    public const string deletionEffectiveAt = 'deletionEffectiveAt';
    public const string lapsed = 'lapsed';
    public const string document = 'document';
    public const string deadline = 'deadline';

    /**
     * @param AccountStandingKind $shown The one fact shown, the one that takes the most away
     * @param bool $blocked Whether an administrator blocked the account
     * @param bool $frozen Whether a lapsed acceptance freezes the account under the refusal setting
     * @param ?int $deletionEffectiveAt Moment the scheduled erasure falls due, milliseconds since the epoch, or null
     * @param list<LegalDocumentStanding> $lapsed Documents whose deadline has passed, whatever the refusal setting says
     */
    public function __construct(
        public AccountStandingKind $shown,
        public bool $blocked,
        public bool $frozen,
        public ?int $deletionEffectiveAt,
        public array $lapsed,
    ) {
    }

    /**
     * The shape the session frame, the admin card and its live frame carry.
     *
     * @return array{shown: string, blocked: bool, frozen: bool, deletionEffectiveAt: ?int,
     *     lapsed: list<array{document: string, deadline: ?string}>} Wire form of the verdict
     */
    public function toArray(): array
    {
        return [
            self::shown => $this->shown->value,
            self::blocked => $this->blocked,
            self::frozen => $this->frozen,
            self::deletionEffectiveAt => $this->deletionEffectiveAt,
            self::lapsed => array_map(
                static fn (LegalDocumentStanding $standing): array => [
                    self::document => $standing->document->value,
                    self::deadline => $standing->deadline,
                ],
                $this->lapsed,
            ),
        ];
    }
}
