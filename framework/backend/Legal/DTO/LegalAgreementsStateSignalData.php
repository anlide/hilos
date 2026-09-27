<?php

declare(strict_types=1);

namespace Hilos\Legal\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** The same acceptance-state payload rides the page response and the person's group. */
final class LegalAgreementsStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string documents = 'documents';

    /** @param list<array<string, mixed>> $documents Acceptance state of each declared document, in declaration order */
    public function __construct(public readonly array $documents)
    {
    }

    /** @return array{documents: list<array<string, mixed>>} Whole state on the wire */
    public function toArray(): array
    {
        return [self::documents => $this->documents];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored state
     * @throws InvalidFormatException When the documents section is absent or not an array
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireArray($data, self::documents));
    }
}
