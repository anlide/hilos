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
use Hilos\Users\AccountStanding;

/**
 * Server → client: the standing of the person an admin card shows, now (HIL-945).
 *
 * What {@see HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE} carries to each subscriber of
 * the card of that person, whenever the standing changes under the open card - a block, a
 * scheduled or canceled deletion, an acceptance, a deadline that passed. The standing travels
 * whole, in the one shape it has everywhere ({@see AccountStanding::toArray()}), so the card
 * reads one verdict rather than three flags.
 */
final class AccountStandingStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string accountStanding = 'accountStanding';

    /**
     * @param int $userId Person the card shows
     * @param array{shown: string, blocked: bool, frozen: bool, deletionEffectiveAt: ?int,
     *     lapsed: list<array{document: string, deadline: ?string}>} $accountStanding Their standing
     * @throws InvalidFormatException When the id names no account (zero or negative)
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $accountStanding,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('Payload key ' . self::userId . ' holds a value that is not a positive integer');
        }
    }

    /**
     * @return array{userId: int, accountStanding: array<string, mixed>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::accountStanding => $this->accountStanding,
        ];
    }

    /**
     * Declares where each field of this frame comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * The person is named by their id, a column the verdict of people shows, and the standing by the one
     * map the card's page data uses too ({@see AccountStanding::wireFields()}), so a viewer's live frame
     * hides exactly what the first render hid ({@see AbstractPage::frameForViewer()}).
     *
     * @return array<string, WireField> Frame field name to where it comes from
     */
    public static function wireFields(): array
    {
        return [
            self::userId => WireField::column(HilosDbContext::users, ObjectUser::id),
            self::accountStanding => WireField::each(AccountStanding::wireFields()),
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the person or the standing is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        $standing = HandshakeResponseSignalData::readAccountStanding($data);
        if ($standing === null) {
            throw new InvalidFormatException('Payload key ' . self::accountStanding . ' is missing');
        }

        return new static(self::requireInt($data, self::userId), $standing);
    }
}
