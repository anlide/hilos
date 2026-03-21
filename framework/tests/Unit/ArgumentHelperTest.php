<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Helpers\Exception\InvalidWorkerIdException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ArgumentHelper (CLI argument parsing for worker processes).
 */
final class ArgumentHelperTest extends TestCase
{
    /**
     * Parses a positive worker index from a well-formed --worker-id=N argument.
     */
    public function testGetWorkerIndexParsesPositiveInteger(): void
    {
        $argv = ['script.php', '--' . WorkerConstants::WORKER_ID_ARG . '=7'];
        $this->assertSame(7, ArgumentHelper::getWorkerIndex($argv));
    }

    /**
     * Throws InvalidWorkerIdException when --worker-id is absent from argv.
     */
    public function testGetWorkerIndexThrowsWhenArgumentMissing(): void
    {
        $this->expectException(InvalidWorkerIdException::class);
        $this->expectExceptionMessage('--worker-id argument is missing');

        ArgumentHelper::getWorkerIndex(['script.php']);
    }

    /**
     * Throws InvalidWorkerIdException when --worker-id= has no value after '='.
     */
    public function testGetWorkerIndexThrowsWhenValueEmpty(): void
    {
        $this->expectException(InvalidWorkerIdException::class);
        $this->expectExceptionMessage('worker index value is empty');

        $argv = ['script.php', '--' . WorkerConstants::WORKER_ID_ARG . '='];
        ArgumentHelper::getWorkerIndex($argv);
    }

    /**
     * Throws InvalidWorkerIdException when the value is not a valid integer string.
     */
    public function testGetWorkerIndexThrowsWhenValueNotInteger(): void
    {
        $this->expectException(InvalidWorkerIdException::class);
        $this->expectExceptionMessage("worker index 'abc' is not a valid integer");

        $argv = ['script.php', '--' . WorkerConstants::WORKER_ID_ARG . '=abc'];
        ArgumentHelper::getWorkerIndex($argv);
    }

    /**
     * Throws InvalidWorkerIdException when worker index is zero.
     */
    public function testGetWorkerIndexThrowsWhenZero(): void
    {
        $this->expectException(InvalidWorkerIdException::class);
        $this->expectExceptionMessage('worker index must be positive');

        $argv = ['script.php', '--' . WorkerConstants::WORKER_ID_ARG . '=0'];
        ArgumentHelper::getWorkerIndex($argv);
    }

    /**
     * Throws InvalidWorkerIdException when worker index is negative.
     */
    public function testGetWorkerIndexThrowsWhenNegative(): void
    {
        $this->expectException(InvalidWorkerIdException::class);
        $this->expectExceptionMessage('worker index must be positive');

        $argv = ['script.php', '--' . WorkerConstants::WORKER_ID_ARG . '=-1'];
        ArgumentHelper::getWorkerIndex($argv);
    }

    /**
     * isMonopolistic returns false when the monopolistic flag is not in argv.
     */
    public function testIsMonopolisticFalseWhenAbsent(): void
    {
        $this->assertFalse(ArgumentHelper::isMonopolistic(['script.php']));
    }

    /**
     * isMonopolistic returns true when the monopolistic flag is present in argv.
     */
    public function testIsMonopolisticTrueWhenPresent(): void
    {
        $flag = '--' . WorkerConstants::MONOPOLISTIC_ARG;
        $this->assertTrue(ArgumentHelper::isMonopolistic(['script.php', $flag]));
    }

    /**
     * buildWorkerIdArg produces the expected --worker-id=N string.
     */
    public function testBuildWorkerIdArg(): void
    {
        $this->assertSame(
            '--' . WorkerConstants::WORKER_ID_ARG . '=3',
            ArgumentHelper::buildWorkerIdArg(3),
        );
    }

    /**
     * buildWorkerArgs returns only the worker-id argument when monopolistic is false.
     */
    public function testBuildWorkerArgsWithoutMonopolistic(): void
    {
        $expected = ['--' . WorkerConstants::WORKER_ID_ARG . '=2'];
        $this->assertSame($expected, ArgumentHelper::buildWorkerArgs(2));
    }

    /**
     * buildWorkerArgs appends the monopolistic flag when requested.
     */
    public function testBuildWorkerArgsWithMonopolistic(): void
    {
        $expected = [
            '--' . WorkerConstants::WORKER_ID_ARG . '=1',
            '--' . WorkerConstants::MONOPOLISTIC_ARG,
        ];
        $this->assertSame($expected, ArgumentHelper::buildWorkerArgs(1, true));
    }
}
