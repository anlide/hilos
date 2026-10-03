<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptancesExportActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The filters an export order carries from the browser to SQL (HIL-1234). */
final class HilosLegalAcceptancesExportActionDTOTest extends TestCase
{
    public function testAnAbsentOrEmptyFilterMeansNoFilter(): void
    {
        $dto = HilosLegalAcceptancesExportActionDTO::fromArray(['document' => '', 'search' => '   ']);

        self::assertNull($dto->document);
        self::assertNull($dto->revisionId);
        self::assertNull($dto->search);
    }

    public function testFiltersUpToTheirColumnsPass(): void
    {
        $dto = HilosLegalAcceptancesExportActionDTO::fromArray([
            'document' => str_repeat('d', 16),
            'revisionId' => str_repeat('r', 32),
            'search' => ' ' . str_repeat('ж', 200) . ' ',
        ]);

        self::assertSame(str_repeat('d', 16), $dto->document);
        self::assertSame(str_repeat('r', 32), $dto->revisionId);
        self::assertSame(str_repeat('ж', 200), $dto->search);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function tooLong(): array
    {
        return [
            'document' => [['document' => str_repeat('d', 17)]],
            'revision' => [['revisionId' => str_repeat('r', 33)]],
            'search' => [['search' => str_repeat('s', 201)]],
        ];
    }

    /**
     * @param array<string, string> $payload Payload with one field over its length
     */
    #[DataProvider('tooLong')]
    public function testAFilterLongerThanItsColumnIsRefused(array $payload): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid export filter');

        HilosLegalAcceptancesExportActionDTO::fromArray($payload);
    }

    public function testANonStringFilterIsAMalformedPayload(): void
    {
        $this->expectException(InvalidFormatException::class);

        HilosLegalAcceptancesExportActionDTO::fromArray(['document' => 7]);
    }
}
