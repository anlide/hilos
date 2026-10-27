<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Moderation\DTO\ModerationRuleAddSignalData;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleAddActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Strict browser input and complete worker transport, without duplicating ORM validation. */
final class ModerationRuleAddDTOTest extends TestCase
{
    public function testRawAndWrappedActionPreserveTheDraftWithoutEnvelopeFields(): void
    {
        $payload = ['kind' => 'hilos_profile_name', 'rule' => "  <b>Правило</b>\n "];
        foreach ([$payload, ['data' => $payload, 'requestId' => 'outside']] as $input) {
            $dto = HilosModerationRuleAddActionDTO::fromArray($input);
            self::assertSame($payload, $dto->toArray());
            self::assertSame(HilosSignalConstants::HILOS_MODERATION_RULE_ADD, $dto->getAction());
            self::assertSame($payload, HilosModerationRuleAddActionDTO::fromJson($dto->toJson())->toArray());
        }
        self::assertSame([], HilosModerationRuleAddActionDTO::SECRET_FIELDS);
        self::assertSame(['kind' => '', 'rule' => ''], HilosModerationRuleAddActionDTO::fromArray(['kind' => '', 'rule' => ''])->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing and mistyped browser fields */
    public static function invalidActions(): iterable
    {
        yield 'missing kind' => [['rule' => 'Rule']];
        yield 'missing rule' => [['kind' => 'hilos_profile_name']];
        foreach (['kind', 'rule'] as $key) {
            foreach ([null, 1, 1.5, true, [], new stdClass()] as $index => $value) {
                yield $key . $index => [array_replace(['kind' => 'hilos_profile_name', 'rule' => 'Rule'], [$key => $value])];
            }
        }
        foreach (['bad', 1, false] as $index => $value) {
            yield 'wrapper' . $index => [['data' => $value]];
        }
    }

    /** @param array<string, mixed> $payload Invalid browser body */
    #[DataProvider('invalidActions')]
    public function testActionRejectsMissingAndMistypedInput(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosModerationRuleAddActionDTO::fromArray($payload);
    }

    public function testInternalRoundtripCarriesCorrelationAndExplicitNulls(): void
    {
        foreach ([null, 'request-1', str_repeat('я', 128)] as $requestId) {
            foreach ([null, 'Rule added.'] as $message) {
                $payload = self::askPayload($requestId, $message);
                $dto = ModerationRuleAddSignalData::fromArray($payload);
                self::assertInstanceOf(HandoverAskInterface::class, $dto);
                self::assertSame($payload, $dto->toArray());
                self::assertSame($payload, ModerationRuleAddSignalData::fromJson($dto->toJson())->toArray());
            }
        }
        self::assertSame(HilosModerationRuleAddActionDTO::kind, ModerationRuleAddSignalData::kind);
        self::assertSame(HilosModerationRuleAddActionDTO::rule, ModerationRuleAddSignalData::rule);
    }

    /** @return iterable<string, array{array<string, mixed>}> Incomplete and invalid transport frames */
    public static function invalidAsks(): iterable
    {
        $valid = self::askPayload(null, null);
        foreach ($valid as $key => $_value) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            foreach ([1, false, []] as $index => $value) {
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
        ModerationRuleAddSignalData::fromArray($payload);
    }

    /**
     * @param ?string $requestId Correlation or explicit null
     * @param ?string $message Success sentence or explicit null
     * @return array<string, mixed> Complete internal frame
     */
    private static function askPayload(?string $requestId, ?string $message): array
    {
        return [
            'kind' => 'hilos_profile_name', 'rule' => "  Raw rule\n", 'replySignal' => 'reply', 'acceptKey' => 'connection',
            'requestId' => $requestId, 'action' => HilosSignalConstants::HILOS_MODERATION_RULE_ADD, 'successMessage' => $message,
        ];
    }
}
