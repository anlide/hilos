<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Generator;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountOperationAnswerSignalDataTest extends TestCase
{
    /** @return array<string, array{string, ?int, ?string}> Every agreed answer and both final-refusal table forms */
    public static function answers(): array
    {
        return [
            'done' => ['done', null, null],
            'uncaptured' => ['uncaptured', null, null],
            'waits' => ['waits', null, null],
            'final refused with table' => ['final_refused', 42, 'project_note'],
            'final refused without table' => ['final_refused', 42, null],
            'refused' => ['refused', null, null],
            'failed' => ['failed', null, null],
        ];
    }

    /**
     * @param string $answer Literal wire answer
     * @param ?int $userId Account of a final refusal
     * @param ?string $table Known referencing table, if any
     */
    #[DataProvider('answers')]
    public function testRoundtripPreservesOnlyTheAgreedAnswerFields(string $answer, ?int $userId, ?string $table): void
    {
        $wire = [
            'operationId' => 9007199254740993,
            'stage' => 'parts',
            'assignmentId' => 9007199254740995,
            'answer' => $answer,
            'refusedUserId' => $userId,
            'refusedTable' => $table,
        ];
        $dto = AccountOperationAnswerSignalData::fromArray($wire);

        self::assertSame($answer, $dto->answer->value);
        self::assertSame($wire, $dto->toArray());
        self::assertEquals($dto, AccountOperationAnswerSignalData::fromArray($dto->toArray()));
    }

    /** The transport answer vocabulary is separate from terminal operation outcomes. */
    public function testAnswerVocabularyContainsExactlyTheSixAgreedValues(): void
    {
        self::assertSame(
            ['done', 'uncaptured', 'waits', 'final_refused', 'refused', 'failed'],
            array_map(static fn(AccountOperationAnswerKind $answer): string => $answer->value, AccountOperationAnswerKind::cases()),
        );
    }

    /** @return Generator<string, array{array<string, mixed>}> Invalid types, unknown answers and misplaced refusal fields */
    public static function malformedPayloads(): Generator
    {
        $valid = ['operationId' => 7, 'stage' => 'parts', 'assignmentId' => 11, 'answer' => 'done'];
        foreach (['operationId', 'stage', 'assignmentId', 'answer'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing ' . $field => [$missing];
            yield 'null ' . $field => [array_replace($valid, [$field => null])];
        }
        yield 'outcome is not answer' => [['operationId' => 7, 'stage' => 'parts', 'assignmentId' => 11, 'outcome' => 'done']];
        foreach (['', 'unknown', 'DONE', 'completed', 1, false, []] as $index => $value) {
            yield 'unknown answer ' . $index => [array_replace($valid, ['answer' => $value])];
        }
        foreach (['7', 7.5, true, []] as $value) {
            yield 'operation type ' . get_debug_type($value) => [array_replace($valid, ['operationId' => $value])];
            yield 'assignment type ' . get_debug_type($value) => [array_replace($valid, ['assignmentId' => $value])];
            yield 'refusal user type ' . get_debug_type($value) => [array_replace($valid, [
                'answer' => 'final_refused', 'refusedUserId' => $value,
            ])];
        }
        yield 'integer stage' => [array_replace($valid, ['stage' => 3])];
        yield 'array stage' => [array_replace($valid, ['stage' => []])];
        yield 'final without user' => [array_replace($valid, ['answer' => 'final_refused'])];
        yield 'final with null user' => [array_replace($valid, ['answer' => 'final_refused', 'refusedUserId' => null])];
        yield 'final with integer table' => [array_replace($valid, [
            'answer' => 'final_refused', 'refusedUserId' => 42, 'refusedTable' => 5,
        ])];
        yield 'final with array table' => [array_replace($valid, [
            'answer' => 'final_refused', 'refusedUserId' => 42, 'refusedTable' => [],
        ])];
        foreach (['done', 'uncaptured', 'waits', 'refused', 'failed'] as $answer) {
            yield $answer . ' with user' => [array_replace($valid, ['answer' => $answer, 'refusedUserId' => 42])];
            yield $answer . ' with table' => [array_replace($valid, ['answer' => $answer, 'refusedTable' => 'project_note'])];
        }
    }

    /** @param array<string, mixed> $payload Malformed participant answer */
    #[DataProvider('malformedPayloads')]
    public function testMalformedAnswerIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountOperationAnswerSignalData::fromArray($payload);
    }
}
