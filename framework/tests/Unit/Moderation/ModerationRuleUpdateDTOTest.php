<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Moderation\DTO\ModerationRuleUpdateSignalData;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Strict browser input and complete worker transport, leaving draft validation to the item action. */
final class ModerationRuleUpdateDTOTest extends TestCase
{
    public function testRawAndWrappedActionPreserveDraftAndSavedTextWithoutEnvelopeFields(): void
    {
        $payload = ['id' => 7, 'kind' => 'hilos_profile_name', 'rule' => "  New <b>Правило</b>\n ", 'expectedRule' => "  <b>Правило</b>\n "];
        foreach ([$payload, ['data' => $payload, 'requestId' => 'outside']] as $input) {
            $dto = HilosModerationRuleUpdateActionDTO::fromArray($input);
            self::assertSame($payload, $dto->toArray());
            self::assertSame(HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE, $dto->getAction());
            self::assertSame($payload, HilosModerationRuleUpdateActionDTO::fromJson($dto->toJson())->toArray());
        }
        self::assertSame([], HilosModerationRuleUpdateActionDTO::SECRET_FIELDS);
        foreach (['', " \t\n ", str_repeat('я', 32768)] as $raw) {
            $payload = ['id' => 7, 'kind' => '', 'rule' => $raw, 'expectedRule' => $raw];
            self::assertSame($payload, HilosModerationRuleUpdateActionDTO::fromArray($payload)->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing and mistyped browser fields */
    public static function invalidActions(): iterable
    {
        $valid = ['id' => 7, 'kind' => 'hilos_profile_name', 'rule' => "  New <b>Правило</b>\n ", 'expectedRule' => 'Rule'];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
        }
        foreach (['kind', 'rule', 'expectedRule'] as $key) {
            foreach ([null, 1, 1.5, true, [], new stdClass()] as $index => $value) {
                yield $key . $index => [array_replace($valid, [$key => $value])];
            }
        }
        foreach ([null, '7', 7.0, true, false, 0, -1, [], new stdClass()] as $index => $value) {
            yield 'id' . $index => [array_replace($valid, ['id' => $value])];
        }
        foreach ([null, 'bad', 1, 1.5, false, true, new stdClass()] as $index => $value) {
            yield 'wrapper' . $index => [array_replace($valid, ['data' => $value])];
        }
        yield 'empty wrapper' => [array_replace($valid, ['data' => []])];
    }

    /** @param array<string, mixed> $payload Invalid browser body */
    #[DataProvider('invalidActions')]
    public function testActionRejectsMissingAndMistypedInput(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosModerationRuleUpdateActionDTO::fromArray($payload);
    }

    public function testInternalRoundtripCarriesCorrelationAndExplicitNulls(): void
    {
        foreach ([null, 'request-1', str_repeat('я', 128)] as $requestId) {
            foreach ([null, 'Rule saved.'] as $message) {
                $payload = self::askPayload($requestId, $message);
                $dto = ModerationRuleUpdateSignalData::fromArray($payload);
                self::assertInstanceOf(HandoverAskInterface::class, $dto);
                self::assertSame($payload, $dto->toArray());
                self::assertSame($payload, ModerationRuleUpdateSignalData::fromJson($dto->toJson())->toArray());
            }
        }
        self::assertSame(HilosModerationRuleUpdateActionDTO::kind, ModerationRuleUpdateSignalData::kind);
        self::assertSame(HilosModerationRuleUpdateActionDTO::id, ModerationRuleUpdateSignalData::id);
        self::assertSame(HilosModerationRuleUpdateActionDTO::rule, ModerationRuleUpdateSignalData::rule);
        self::assertSame(HilosModerationRuleUpdateActionDTO::expectedRule, ModerationRuleUpdateSignalData::expectedRule);
    }

    /** @return iterable<string, array{array<string, mixed>}> Incomplete and invalid transport frames */
    public static function invalidAsks(): iterable
    {
        $valid = self::askPayload(null, null);
        foreach ($valid as $key => $_value) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            $invalid = $key === 'id' ? ['7', 7.0, true, false, 0, -1, []] : [1, 1.5, true, false, []];
            foreach ($invalid as $index => $value) {
                yield $key . $index => [array_replace($valid, [$key => $value])];
            }
            if (!in_array($key, ['requestId', 'successMessage'], true)) {
                yield 'null ' . $key => [array_replace($valid, [$key => null])];
            }
        }
        foreach (['replySignal', 'acceptKey', 'action'] as $key) {
            yield 'empty ' . $key => [array_replace($valid, [$key => '  '])];
        }
        yield 'long correlation' => [array_replace($valid, ['requestId' => str_repeat('я', 129)])];
        yield 'invalid UTF-8 correlation' => [array_replace($valid, ['requestId' => "\xFF"])];
    }

    /** @param array<string, mixed> $payload Invalid internal body */
    #[DataProvider('invalidAsks')]
    public function testInternalAskRejectsMissingAndMistypedTransport(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ModerationRuleUpdateSignalData::fromArray($payload);
    }

    /**
     * @param ?string $requestId Correlation or explicit null
     * @param ?string $message Success sentence or explicit null
     * @return array<string, mixed> Complete internal frame
     */
    private static function askPayload(?string $requestId, ?string $message): array
    {
        return [
            'id' => 7, 'kind' => 'hilos_profile_name', 'rule' => "  New <b>Правило</b>\n ", 'expectedRule' => "  Raw rule\n",
            'replySignal' => 'reply', 'acceptKey' => 'connection',
            'requestId' => $requestId, 'action' => HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE, 'successMessage' => $message,
        ];
    }
}
