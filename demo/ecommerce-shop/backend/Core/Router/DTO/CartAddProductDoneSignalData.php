<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Cart owner → the product agent that originally forwarded the click. */
final class CartAddProductDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string productSlug = 'productSlug';

    private const string answer = 'answer';

    /**
     * @param string $productSlug Original product agent index from the request
     * @param HandoverAnswerSignalData $answer Outcome and browser correlation
     * @throws InvalidFormatException When the original product address is empty
     */
    public function __construct(
        public readonly string $productSlug,
        public readonly HandoverAnswerSignalData $answer,
    ) {
        if ($productSlug === '') {
            throw new InvalidFormatException('The original product address is required.');
        }
    }

    /**
     * @param array<string, mixed> $data Original product address and nested answer
     * @return static Outcome addressed to the original product agent
     * @throws InvalidFormatException When the address or answer is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::productSlug),
            HandoverAnswerSignalData::fromArray(self::requireArray($data, self::answer)),
        );
    }

    /** @return array{productSlug: string, answer: array<string, mixed>} Transport payload */
    public function toArray(): array
    {
        return [self::productSlug => $this->productSlug, self::answer => $this->answer->toArray()];
    }
}
