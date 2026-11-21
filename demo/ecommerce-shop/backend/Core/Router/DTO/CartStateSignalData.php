<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * CartStateSignalData - the whole cart of one guest session or one person, told to every tab that holds it.
 *
 * Sent by the cart's owner to the cart's group after each change and whenever a tab is put in
 * the group: the guest carts library for a session, the person's agent for an account. Nothing
 * asked for it on the wire, so it carries no envelope; the tab replaces what it knew with it.
 */
final class CartStateSignalData extends SignalData implements SignalDataInterface
{
    public const string USER_ID = 'userId';
    public const string LINES = 'lines';
    public const string PRODUCT_ID = 'productId';
    public const string QUANTITY = 'quantity';

    /**
     * @param ?int $userId Person whose cart it is, null for the cart of a guest session
     * @param list<array{productId: int, quantity: int}> $lines Every line, in the order the owner gave: the order they were put in
     */
    public function __construct(
        public readonly ?int $userId,
        public readonly array $lines,
    ) {
        parent::__construct([self::USER_ID => $userId, self::LINES => $lines]);
    }

    /**
     * Roundtrip reconstruction used when the signal crosses the worker -> daemon IPC boundary.
     *
     * @param array<string, mixed> $data Serialized payload
     * @return static DTO instance
     * @throws InvalidFormatException When the person is absent or not an id, or the lines are not a list of whole lines
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(self::USER_ID, $data)) {
            throw new InvalidFormatException('Payload carries no user id field');
        }
        $lines = self::requireArray($data, self::LINES);
        if (!array_is_list($lines)) {
            throw new InvalidFormatException('Payload carries no list under key ' . self::LINES);
        }

        return new static(self::optionalInt($data, self::USER_ID), array_map(self::readLine(...), $lines));
    }

    /**
     * Reads one line of the cart.
     *
     * @param mixed $line One entry of the serialized lines
     * @return array{productId: int, quantity: int} The line
     * @throws InvalidFormatException When the entry is not a line, or its quantity is below the fewest a line keeps
     */
    private static function readLine(mixed $line): array
    {
        if (!is_array($line)) {
            throw new InvalidFormatException('Payload carries a cart line that is not an object');
        }
        $quantity = self::requireInt($line, self::QUANTITY);
        if ($quantity < CartLineRules::QUANTITY_MIN) {
            throw new InvalidFormatException('Payload carries a cart line below the fewest units a line keeps');
        }

        return [self::PRODUCT_ID => self::requireInt($line, self::PRODUCT_ID), self::QUANTITY => $quantity];
    }
}
