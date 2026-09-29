<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Constants\HilosSignalConstants;
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
     * Empty: a viewer is sent the frame with every field hidden ({@see AbstractPage::frameForViewer()})
     * until the leaf that declares the fields of people opens them (HIL-1254).
     *
     * @return array<string, WireField> Frame field name to where it comes from
     */
    public static function wireFields(): array
    {
        return [];
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
