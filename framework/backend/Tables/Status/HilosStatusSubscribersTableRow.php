<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\View\Item\StatusSubscriber;

/** Subscription browser row; link secrets and delivery preferences never enter this projection. */
final class HilosStatusSubscribersTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string email = 'email';
    public const string subscribedAt = 'subscribedAt';
    public const string confirmed = 'confirmed';

    /**
     * @param int $id Persisted subscription primary id
     * @param string|array{_hidden: true} $email Address, or the shared viewer hidden mark
     * @param int $subscribedAt UTC epoch milliseconds
     * @param bool $confirmed Whether the address is confirmed
     */
    public function __construct(
        public readonly int $id,
        public readonly string|array $email,
        public readonly int $subscribedAt,
        public readonly bool $confirmed,
    ) {
    }

    /**
     * @param StatusSubscriber $subscriber Persisted non-secret View row
     * @return self Minimal browser projection
     */
    public static function fromSubscriber(StatusSubscriber $subscriber): self
    {
        return new self($subscriber->id, $subscriber->email, $subscriber->subscribedAt, $subscriber->confirmed);
    }

    /** @return int Persisted primary key */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /** @return string Row key field */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @param array<string, mixed> $data Browser row, possibly redacted
     * @return static Typed browser row
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::id),
            HiddenValue::isMark($data[self::email] ?? null) ? HiddenValue::mark() : self::requireString($data, self::email),
            self::requireInt($data, self::subscribedAt), self::requireBool($data, self::confirmed));
    }

    /** @return array<string, mixed> Exactly the four subscriber slot fields */
    public function toArray(): array
    {
        return [self::id => $this->id, self::email => $this->email,
            self::subscribedAt => $this->subscribedAt, self::confirmed => $this->confirmed];
    }
}
