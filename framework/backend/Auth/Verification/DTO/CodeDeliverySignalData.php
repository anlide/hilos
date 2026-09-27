<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification\DTO;

use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;

/**
 * What the installation can deliver a one-time code to, sent to every connection (HIL-1102).
 *
 * Sent by {@see SettingsLibraryAgent} after a settings write changes availability. The
 * handshake ({@see HandshakeResponseSignalData}) carries the same node for a new connection.
 * This is its own frame because delivery has readers independent of the sign-in method set,
 * just as the second-factor policy does. The whole answer travels so receiving it never
 * depends on which earlier frames a connection has seen.
 */
final class CodeDeliverySignalData extends BaseDTO implements SignalDataInterface
{
    public const string codeDelivery = 'codeDelivery';
    public const string email = 'email';
    public const string phone = 'phone';

    /**
     * @param array{email: bool, phone: bool} $codeDelivery Deliverability per identifier kind
     */
    public function __construct(public readonly array $codeDelivery)
    {
    }

    /**
     * Reads without caching or network access; unreadable configuration fails open.
     *
     * @return self The current delivery frame
     */
    public static function current(): self
    {
        return new self(new CodeDeliveryAvailability()->toArray());
    }

    /**
     * @return array{codeDelivery: array{email: bool, phone: bool}} DTO payload for transport
     */
    public function toArray(): array
    {
        return [self::codeDelivery => $this->codeDelivery];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Parsed delivery frame
     * @throws InvalidFormatException When the delivery node or either boolean is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        $delivery = self::requireArray($data, self::codeDelivery);

        return new static([
            self::email => self::requireBool($delivery, self::email),
            self::phone => self::requireBool($delivery, self::phone),
        ]);
    }
}
