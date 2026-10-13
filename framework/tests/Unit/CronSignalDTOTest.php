<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cron schedule transport and compatibility with signals sent before HIL-1456. */
final class CronSignalDTOTest extends TestCase
{
    /** The exact fired expression survives the transport boundary. */
    public function testExpressionRoundtrip(): void
    {
        $signal = new CronSignalDTO('sweep', '*/5 * * * *');
        $this->assertSame(['cronName' => 'sweep', 'cronExpression' => '*/5 * * * *'], $signal->toArray());
        $this->assertEquals($signal, CronSignalDTO::fromArray($signal->toArray()));
    }

    /** The old constructor and absent or null expression retain the old payload shape. */
    public function testLegacyAndNullExpression(): void
    {
        $legacy = new CronSignalDTO(cronName: 'sweep');
        $this->assertNull($legacy->cronExpression);
        $this->assertSame(['cronName' => 'sweep'], $legacy->toArray());
        $this->assertEquals($legacy, CronSignalDTO::fromArray(['cronName' => 'sweep']));
        $this->assertEquals($legacy, CronSignalDTO::fromArray(['cronName' => 'sweep', 'cronExpression' => null]));
    }

    /** Required means present and typed; the DTO does not validate cron syntax. */
    public function testEmptyStringsRemainExplicitInput(): void
    {
        $signal = CronSignalDTO::fromArray(['cronName' => '', 'cronExpression' => '']);
        $this->assertSame(['cronName' => '', 'cronExpression' => ''], $signal->toArray());
    }

    /** @param array<string, mixed> $payload Missing name or incorrectly typed field */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        CronSignalDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid transport field shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'missing name' => [[]];
        yield 'null name' => [['cronName' => null]];
        yield 'integer name' => [['cronName' => 1]];
        yield 'integer expression' => [['cronName' => 'sweep', 'cronExpression' => 5]];
        yield 'false expression' => [['cronName' => 'sweep', 'cronExpression' => false]];
        yield 'array expression' => [['cronName' => 'sweep', 'cronExpression' => []]];
    }
}
