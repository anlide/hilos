<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountStanding;

/**
 * Server → client: the standing of the person an admin card shows, now (HIL-945).
 *
 * What {@see HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE} carries to each subscriber of
 * the card of that person, whenever the standing changes under the open card - a block, a
 * scheduled or canceled deletion, an acceptance, a deadline that passed. The standing travels
 * whole, in the one shape it has everywhere ({@see AccountStanding::toArray()}), so the card
 * reads one verdict rather than three flags. The same frame carries erasure progress, including
 * changes to expected owners or waits while the standing stays erasing (HIL-1954).
 */
final class AccountStandingStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string accountStanding = 'accountStanding';
    public const string accountErasure = 'accountErasure';

    /**
     * @param int $userId Person the card shows
     * @param array{shown: string, blocked: bool, frozen: bool, deletionEffectiveAt: ?int, deletionGraceDays: ?int,
     *     lapsed: list<array{document: string, deadline: ?string}>,
     *     window: list<array{document: string, deadline: ?string}>,
     *     mergedInto: ?int, mergedIntoName: ?string} $accountStanding Their standing
     * @param ?array{startedAt: int, parts: list<array{participant: string, owner: string, userId: int, done: bool}>,
     *     waits: list<array{participant: string, userId: int}>, refusals: list<array{userId: int, table: ?string}>}
     *     $accountErasure Begun erasure progress, or null
     * @throws InvalidFormatException When the id names no account (zero or negative)
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $accountStanding,
        public readonly ?array $accountErasure = null,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('Payload key ' . self::userId . ' holds a value that is not a positive integer');
        }
    }

    /**
     * @return array{userId: int, accountStanding: array<string, mixed>, accountErasure: ?array<string, mixed>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::accountStanding => $this->accountStanding,
            self::accountErasure => $this->accountErasure,
        ];
    }

    /**
     * Declares where each field of this frame comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * The person is named by their id, a column the verdict of people shows, and the standing by the one
     * map the card's page data uses too ({@see AccountStanding::wireFields()}), so a viewer's live frame
     * hides exactly what the first render hid ({@see AbstractPage::frameForViewer()}). Erasure progress
     * shares {@see AccountErasureProgress::wireFields()} with that first answer too.
     *
     * @return array<string, WireField> Frame field name to where it comes from
     */
    public static function wireFields(): array
    {
        return [
            self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
            self::accountStanding => WireField::each(AccountStanding::wireFields()),
            self::accountErasure => WireField::each(AccountErasureProgress::wireFields()),
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the person or standing is missing, or a supplied block is malformed
     */
    public static function fromArray(array $data): static
    {
        $standing = HandshakeResponseSignalData::readAccountStanding($data);
        if ($standing === null) {
            throw new InvalidFormatException('Payload key ' . self::accountStanding . ' is missing');
        }

        return new static(self::requireInt($data, self::userId), $standing, self::optionalArray($data, self::accountErasure));
    }
}
