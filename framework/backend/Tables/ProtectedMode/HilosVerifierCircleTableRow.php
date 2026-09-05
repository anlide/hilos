<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;

/**
 * Backend row payload for the verifier circle table (HIL-643).
 *
 * Three fields come off the membership and one is asked of the runtime when the row is
 * built. `online` is not stored anywhere and is not watched either: it says whether the
 * person had a browser open at the moment this row was drawn, which is the only thing the
 * operator needs from it - the freeze photographs the same question for real, once, and
 * that photograph is what actually decides who gets in.
 */
final class HilosVerifierCircleTableRow extends AbstractTableRow
{
    public const string id = ObjectVerifierCircleMember::id;
    public const string identityType = ObjectVerifierCircleMember::identityType;
    public const string identifier = ObjectVerifierCircleMember::identifier;
    public const string online = 'online';

    /**
     * @param int $id Membership row key
     * @param string $identityType Identity type the person was named under
     * @param string $identifier Address the person was named by
     * @param bool $online Whether that person held a live connection when this row was built
     */
    public function __construct(
        public int $id,
        public string $identityType,
        public string $identifier,
        public bool $online,
    ) {
    }

    /**
     * @return int Stable row key: the membership id, which no rename of the address moves
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * Serializes the row to the verifier circle table payload shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::identityType => $this->identityType,
            self::identifier => $this->identifier,
            self::online => $this->online,
        ];
    }

    /**
     * Builds a verifier circle row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @throws InvalidFormatException When a field of the row is absent or of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            identityType: self::requireString($data, self::identityType),
            identifier: self::requireString($data, self::identifier),
            online: self::requireBool($data, self::online),
        );
    }
}
