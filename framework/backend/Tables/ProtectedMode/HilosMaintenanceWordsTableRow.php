<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the maintenance words table (HIL-1677).
 *
 * Represents the words announcing a maintenance operation on the locked-out surface and banner.
 */
final class HilosMaintenanceWordsTableRow extends AbstractTableRow
{
    public const string operation = 'operation';
    public const string title = 'title';
    public const string message = 'message';
    public const string bannerMessage = 'bannerMessage';
    public const string usesDefault = 'usesDefault';

    /**
     * @param string $operation Protected-mode operation key
     * @param ?string $title Heading of the maintenance surface
     * @param ?string $message Sentence shown under the heading
     * @param ?string $bannerMessage Sentence shown on the banner
     * @param bool $usesDefault Whether this entry falls back to the default operation words
     */
    public function __construct(
        public string $operation,
        public ?string $title,
        public ?string $message,
        public ?string $bannerMessage,
        public bool $usesDefault,
    ) {
    }

    /**
     * @return string Stable row key: the operation name
     */
    public function getRowKey(): string
    {
        return $this->operation;
    }

    /**
     * @return string Payload key carrying the row key
     */
    public static function keyField(): string
    {
        return self::operation;
    }

    /**
     * @return array{operation: string, title: ?string, message: ?string, bannerMessage: ?string, usesDefault: bool}
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
            self::title => $this->title,
            self::message => $this->message,
            self::bannerMessage => $this->bannerMessage,
            self::usesDefault => $this->usesDefault,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            operation: self::requireString($data, self::operation),
            title: self::optionalString($data, self::title),
            message: self::optionalString($data, self::message),
            bannerMessage: self::optionalString($data, self::bannerMessage),
            usesDefault: self::requireBool($data, self::usesDefault),
        );
    }
}
