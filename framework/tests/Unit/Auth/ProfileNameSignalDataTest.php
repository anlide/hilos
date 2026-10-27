<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth;

use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Auth\Library\DTO\ProfileNameBeginSignalData;
use Hilos\Auth\Library\DTO\ProfileNameContinueSignalData;
use Hilos\Auth\Session\DTO\ProfileNameStateSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The internal name-change requests and the public session snapshot have strict, distinct boundaries. */
final class ProfileNameSignalDataTest extends TestCase
{
    /**
     * @param class-string<BaseDTO> $dtoClass Payload reader
     * @param array<string, mixed> $payload Complete valid payload
     */
    #[DataProvider('validPayloads')]
    public function testExplicitArrayAndJsonRoundtrip(string $dtoClass, array $payload): void
    {
        $dto = $dtoClass::fromArray($payload);
        self::assertSame($payload, $dto->toArray());
        self::assertSame($payload, $dtoClass::fromJson($dto->toJson())->toArray());
    }

    /** @return iterable<string, array{class-string<BaseDTO>, array<string, mixed>}> Complete payloads */
    public static function validPayloads(): iterable
    {
        yield 'begin' => [ProfileNameBeginSignalData::class, [
            'sessionToken' => 'internal-cookie', 'userId' => 7, 'name' => 'Alexandra', 'originAcceptKey' => 'origin',
        ]];
        yield 'continue' => [ProfileNameContinueSignalData::class, ['sessionTokenHash' => 'session-hash', 'requestId' => 'request']];
        foreach (['applying', 'refused', 'done'] as $phase) {
            yield 'advance ' . $phase => [ProfileNameAdvanceSignalData::class, [
                'sessionTokenHash' => 'session-hash', 'requestId' => 'request', 'phase' => $phase,
                'refusal' => $phase === 'refused' ? 'This name was not accepted.' : null,
            ]];
        }
        yield 'cleared identity' => [ProfileNameStateSignalData::class, ['userId' => null, 'change' => null]];
        yield 'no operation' => [ProfileNameStateSignalData::class, ['userId' => 7, 'change' => null]];
        foreach (['checking', 'applying', 'refused', 'done'] as $phase) {
            yield 'state ' . $phase => [ProfileNameStateSignalData::class, [
                'userId' => 7,
                'change' => [
                    'requestId' => 'request', 'name' => 'Alexandra', 'phase' => $phase,
                    'refusal' => $phase === 'refused' ? 'This name was not accepted.' : null,
                ],
            ]];
        }
    }

    /**
     * @param class-string<BaseDTO> $dtoClass Payload reader
     * @param array<string, mixed> $payload Malformed payload
     */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadIsRefused(string $dtoClass, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }

    /** @return iterable<string, array{class-string<BaseDTO>, array<string, mixed>}> Broken fields and phase combinations */
    public static function malformedPayloads(): iterable
    {
        foreach (self::validPayloads() as $case => [$dtoClass, $payload]) {
            foreach ($payload as $key => $value) {
                $missing = $payload;
                unset($missing[$key]);
                yield $case . ' missing ' . $key => [$dtoClass, $missing];
                $mistyped = $payload;
                $mistyped[$key] = true;
                yield $case . ' mistyped ' . $key => [$dtoClass, $mistyped];
                if ($value !== null && !in_array($key, ['userId', 'change'], true)) {
                    $mistyped[$key] = null;
                    yield $case . ' null ' . $key => [$dtoClass, $mistyped];
                }
            }
        }
        $advance = ['sessionTokenHash' => 'session-hash', 'requestId' => 'request', 'phase' => 'done', 'refusal' => null];
        foreach (['checking', '', 'unknown', 'DONE'] as $phase) {
            yield 'invalid advance phase ' . $phase => [ProfileNameAdvanceSignalData::class, array_replace($advance, ['phase' => $phase])];
        }
        yield 'refused advance without sentence' => [ProfileNameAdvanceSignalData::class, array_replace($advance, ['phase' => 'refused'])];
        yield 'done advance with refusal' => [ProfileNameAdvanceSignalData::class, array_replace($advance, ['refusal' => 'Refused.'])];
        yield 'empty refusal advance' => [ProfileNameAdvanceSignalData::class, array_replace($advance, ['phase' => 'refused', 'refusal' => ''])];

        $change = ['requestId' => 'request', 'name' => 'Alexandra', 'phase' => 'checking', 'refusal' => null];
        foreach ($change as $key => $value) {
            $missing = $change;
            unset($missing[$key]);
            yield 'state change missing ' . $key => [ProfileNameStateSignalData::class, ['userId' => 7, 'change' => $missing]];
            foreach ([false, [], 7] as $wrong) {
                $mistyped = array_replace($change, [$key => $wrong]);
                yield 'state change mistyped ' . $key . ' ' . get_debug_type($wrong)
                    => [ProfileNameStateSignalData::class, ['userId' => 7, 'change' => $mistyped]];
            }
        }
        foreach ([[], [$change], ['phase' => 'done']] as $index => $wrong) {
            yield 'incomplete nested shape ' . $index => [ProfileNameStateSignalData::class, ['userId' => 7, 'change' => $wrong]];
        }
        yield 'state without identity' => [ProfileNameStateSignalData::class, ['userId' => null, 'change' => $change]];
        foreach ([
            ['phase' => 'unknown'], ['phase' => ''], ['requestId' => null], ['name' => null],
            ['phase' => null], ['phase' => 'refused'], ['phase' => 'refused', 'refusal' => ''], ['refusal' => 'Refused.'],
        ] as $index => $wrong) {
            yield 'invalid nested value ' . $index => [
                ProfileNameStateSignalData::class, ['userId' => 7, 'change' => array_replace($change, $wrong)],
            ];
        }
        yield 'string identity' => [ProfileNameStateSignalData::class, ['userId' => '7', 'change' => null]];
        yield 'string begin identity' => [ProfileNameBeginSignalData::class, [
            'sessionToken' => 'internal-cookie', 'userId' => '7', 'name' => 'Alexandra', 'originAcceptKey' => 'origin',
        ]];
    }

    public function testBeginDeclaresTheInternalCookieSecret(): void
    {
        self::assertSame(['sessionToken'], ProfileNameBeginSignalData::SECRET_FIELDS);
    }

    public function testStateProjectsOnlyPublicFieldsEvenWhenGivenAFullRuntimeRow(): void
    {
        $row = HilosProfileNameChange::create('private-hash', 7, 'request', 'private-origin', 'Alexandra', 1000, 31000)->toArray();
        $row['sessionToken'] = 'private-cookie';
        $expected = [
            'userId' => 7,
            'change' => ['requestId' => 'request', 'name' => 'Alexandra', 'phase' => 'checking', 'refusal' => null],
        ];

        self::assertSame($expected, new ProfileNameStateSignalData(7, $row)->toArray());
        self::assertSame($expected, ProfileNameStateSignalData::fromArray([
            'userId' => 7, 'change' => $row, 'sessionToken' => 'private-cookie', 'sessionTokenHash' => 'private-hash',
        ])->toArray());
    }

    public function testDirectConstructionCannotPublishAContradictoryPhase(): void
    {
        $this->expectException(InvalidFormatException::class);
        new ProfileNameAdvanceSignalData('session-hash', 'request', 'done', 'This name was not accepted.');
    }
}
