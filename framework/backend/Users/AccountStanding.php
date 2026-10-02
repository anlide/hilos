<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\LegalAcceptance;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Legal\LegalDocumentStanding;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Users\DTO\AccountStandingStateSignalData;

/**
 * The standing of one account: three independent facts and the one of them that is shown (HIL-945).
 *
 * A block and a scheduled deletion are stored; a freeze is not - it is what the acceptance records
 * and the revision catalog say today under the installation's refusal setting. None of the three
 * replaces another, so the verdict names each of them and picks the one to show separately
 * ({@see AccountStandingKind}). Composed in exactly one place, {@see AccountStandingResolver},
 * and read by the session frame, the page guard and the admin card alike.
 *
 * The documents still inside their window ride along (HIL-500): they take nothing away and play no
 * part in the verdict, but they are what the "the terms have changed" screen and its reminder in
 * the header are drawn from, and the session frame is where a tab learns them without asking.
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
    public const string window = 'window';
    public const string document = 'document';
    public const string deadline = 'deadline';

    /**
     * @param AccountStandingKind $shown The one fact shown, the one that takes the most away
     * @param bool $blocked Whether an administrator blocked the account
     * @param bool $frozen Whether a lapsed acceptance freezes the account under the refusal setting
     * @param ?int $deletionEffectiveAt Moment the scheduled erasure falls due, milliseconds since the epoch, or null
     * @param list<LegalDocumentStanding> $lapsed Documents whose deadline has passed, whatever the refusal setting says
     * @param list<LegalDocumentStanding> $window Documents whose deadline is still ahead, whatever the refusal setting says
     */
    public function __construct(
        public AccountStandingKind $shown,
        public bool $blocked,
        public bool $frozen,
        public ?int $deletionEffectiveAt,
        public array $lapsed,
        public array $window,
    ) {
    }

    /**
     * The shape the session frame, the admin card and its live frame carry.
     *
     * @return array{shown: string, blocked: bool, frozen: bool, deletionEffectiveAt: ?int,
     *     lapsed: list<array{document: string, deadline: ?string}>,
     *     window: list<array{document: string, deadline: ?string}>} Wire form of the verdict
     */
    public function toArray(): array
    {
        return [
            self::shown => $this->shown->value,
            self::blocked => $this->blocked,
            self::frozen => $this->frozen,
            self::deletionEffectiveAt => $this->deletionEffectiveAt,
            self::lapsed => self::documentsToArray($this->lapsed),
            self::window => self::documentsToArray($this->window),
        ];
    }

    /**
     * Declares where each field of the standing comes from, for a viewer of the admin view mode (HIL-1254).
     *
     * One map serves the two places an admin card carries the standing: its page data
     * ({@see AbstractHilosUserPage}) and its live frame ({@see AccountStandingStateSignalData}). The block
     * is the person's column and its verdict decides. Which fact is shown and the freeze are worked out of
     * the stored facts, and the documents with their deadlines are the revision catalog and acceptance
     * records that are not personal ({@see LegalAcceptance}). The date a scheduled erasure falls due is
     * opened by the owner's word (2026-10-01): it names nobody, and without it the card would tell a
     * viewer no deletion is scheduled; the columns of the deletion table stay hidden.
     *
     * @return array<string, WireField> Standing field to where it comes from
     */
    public static function wireFields(): array
    {
        $documents = WireField::each([
            self::document => WireField::notPersonal(),
            self::deadline => WireField::notPersonal(),
        ]);

        return [
            self::shown => WireField::notPersonal(),
            self::blocked => WireField::column(HilosDbContext::users, ObjectUser::block),
            self::frozen => WireField::notPersonal(),
            self::deletionEffectiveAt => WireField::notPersonal(),
            self::lapsed => $documents,
            self::window => $documents,
        ];
    }

    /**
     * Wire form of one list of documents: the document and its deadline, nothing else.
     *
     * @param list<LegalDocumentStanding> $documents Documents of one standing
     * @return list<array{document: string, deadline: ?string}> The list as it travels
     */
    private static function documentsToArray(array $documents): array
    {
        return array_map(
            static fn (LegalDocumentStanding $standing): array => [
                self::document => $standing->document->value,
                self::deadline => $standing->deadline,
            ],
            $documents,
        );
    }
}
