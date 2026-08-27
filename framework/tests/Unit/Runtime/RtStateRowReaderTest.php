<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\RtState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three contracts a runtime row is read by, told apart on one state (HIL-738).
 *
 * A row arrives from another node, and the state that receives it has to answer three
 * different questions about every key: the field is required and a row without it is
 * broken; the field is legitimately empty and its absence is a null; the frame is a diff
 * and a key it does not carry means the field did not change. The three answers look alike
 * at the call site and are not alike at all, so what is asserted here is the difference -
 * most sharply on one nullable field, where a diff without the key keeps the old value and
 * a diff carrying null clears it.
 *
 * The refusal is asserted by the key it names, because that key is what the receiving
 * worker logs when it drops the row.
 */
final class RtStateRowReaderTest extends TestCase
{
    /**
     * @return list<array{0: string}> Keys the row cannot be built without
     */
    public static function requiredKeys(): array
    {
        return [
            [RowReaderTestState::id],
            [RowReaderTestState::name],
            [RowReaderTestState::weight],
            [RowReaderTestState::active],
            [RowReaderTestState::meta],
            [RowReaderTestState::tags],
        ];
    }

    /**
     * @param string $key Required key under test
     */
    #[DataProvider('requiredKeys')]
    public function testARowMissingARequiredFieldIsRefused(string $key): void
    {
        $row = self::row();
        unset($row[$key]);

        $this->assertRefusedRow($row, $key);
    }

    /**
     * @param string $key Required key under test
     */
    #[DataProvider('requiredKeys')]
    public function testARequiredFieldHoldingNullIsRefused(string $key): void
    {
        $this->assertRefusedRow(self::row([$key => null]), $key);
    }

    /**
     * @return list<array{0: string, 1: mixed}> Required key and a value of a type it does not read
     */
    public static function requiredKeysWithAForeignValue(): array
    {
        return [
            [RowReaderTestState::id, 7],
            [RowReaderTestState::name, ['first']],
            [RowReaderTestState::weight, '7'],
            [RowReaderTestState::active, 1],
            [RowReaderTestState::meta, 'shard'],
            [RowReaderTestState::tags, 'alpha'],
        ];
    }

    /**
     * @param string $key Required key under test
     * @param mixed $value Value of a type the key does not read
     */
    #[DataProvider('requiredKeysWithAForeignValue')]
    public function testARequiredFieldOfAnotherTypeIsRefused(string $key, mixed $value): void
    {
        $this->assertRefusedRow(self::row([$key => $value]), $key);
    }

    public function testARequiredFloatWidensAnInteger(): void
    {
        $state = RowReaderTestState::fromRow(self::row([RowReaderTestState::ratio => 3]));

        $this->assertSame(3.0, $state->ratio);
    }

    public function testARequiredFloatRefusesANumberWrittenAsText(): void
    {
        $this->assertRefusedRow(self::row([RowReaderTestState::ratio => '5']), RowReaderTestState::ratio);
    }

    public function testARequiredBooleanTakesFalseAsAValue(): void
    {
        $state = RowReaderTestState::fromRow(self::row([RowReaderTestState::active => false]));

        $this->assertFalse($state->active);
    }

    public function testAnEmptyListIsAValidStringList(): void
    {
        $state = RowReaderTestState::fromRow(self::row([RowReaderTestState::tags => []]));

        $this->assertSame([], $state->tags);
    }

    public function testAnAssociativeArrayIsNotAStringList(): void
    {
        $row = self::row([RowReaderTestState::tags => ['first' => 'alpha']]);

        $this->assertRefusedRow($row, RowReaderTestState::tags);
    }

    public function testAStringListCarryingAnotherTypeIsRefused(): void
    {
        $row = self::row([RowReaderTestState::tags => ['alpha', 7]]);

        $this->assertRefusedRow($row, RowReaderTestState::tags);
    }

    /**
     * @return list<array{0: string}> Keys the row is allowed to leave empty
     */
    public static function optionalKeys(): array
    {
        return [
            [RowReaderTestState::note],
            [RowReaderTestState::retries],
            [RowReaderTestState::score],
        ];
    }

    /**
     * @param string $key Optional key under test
     */
    #[DataProvider('optionalKeys')]
    public function testAnOptionalFieldIsNullWhenTheKeyIsAbsent(string $key): void
    {
        $row = self::row();
        unset($row[$key]);

        $this->assertNull(RowReaderTestState::fromRow($row)->$key);
    }

    /**
     * @param string $key Optional key under test
     */
    #[DataProvider('optionalKeys')]
    public function testAnOptionalFieldIsNullWhenTheKeyHoldsNull(string $key): void
    {
        $this->assertNull(RowReaderTestState::fromRow(self::row([$key => null]))->$key);
    }

    /**
     * @return list<array{0: string, 1: mixed}> Optional key and a value of a type it does not read
     */
    public static function optionalKeysWithAForeignValue(): array
    {
        return [
            [RowReaderTestState::note, 7],
            [RowReaderTestState::retries, '3'],
            [RowReaderTestState::score, 'high'],
        ];
    }

    /**
     * @param string $key Optional key under test
     * @param mixed $value Value of a type the key does not read
     */
    #[DataProvider('optionalKeysWithAForeignValue')]
    public function testAFilledOptionalFieldOfAnotherTypeIsRefused(string $key, mixed $value): void
    {
        $this->assertRefusedRow(self::row([$key => $value]), $key);
    }

    public function testAnOptionalFloatWidensAnInteger(): void
    {
        $state = RowReaderTestState::fromRow(self::row([RowReaderTestState::score => 2]));

        $this->assertSame(2.0, $state->score);
    }

    public function testADiffCarryingNoKeysLeavesEveryFieldAsItWas(): void
    {
        $state = RowReaderTestState::fromRow(self::row());

        $state->applyDiff([]);

        $this->assertSame(self::row(), $state->toArray());
    }

    public function testADiffAppliesEveryFieldItCarries(): void
    {
        $state = RowReaderTestState::fromRow(self::row());

        $state->applyDiff([
            RowReaderTestState::name => 'second',
            RowReaderTestState::weight => 0,
            RowReaderTestState::ratio => 0.0,
            RowReaderTestState::active => false,
            RowReaderTestState::meta => [],
            RowReaderTestState::tags => [],
            RowReaderTestState::note => 'rewritten',
            RowReaderTestState::retries => 0,
            RowReaderTestState::score => 0.0,
        ]);

        $this->assertSame(self::row([
            RowReaderTestState::name => 'second',
            RowReaderTestState::weight => 0,
            RowReaderTestState::ratio => 0.0,
            RowReaderTestState::active => false,
            RowReaderTestState::meta => [],
            RowReaderTestState::tags => [],
            RowReaderTestState::note => 'rewritten',
            RowReaderTestState::retries => 0,
            RowReaderTestState::score => 0.0,
        ]), $state->toArray());
    }

    /**
     * @return list<array{0: string, 1: mixed}> Patched key and a value of a type it does not read
     */
    public static function patchedKeysWithAForeignValue(): array
    {
        return [
            [RowReaderTestState::name, 7],
            [RowReaderTestState::weight, '7'],
            [RowReaderTestState::ratio, '1.5'],
            [RowReaderTestState::active, 1],
            [RowReaderTestState::meta, 'shard'],
            [RowReaderTestState::tags, ['alpha', 7]],
            [RowReaderTestState::note, 7],
            [RowReaderTestState::retries, '3'],
            [RowReaderTestState::score, 'high'],
        ];
    }

    /**
     * @param string $key Patched key under test
     * @param mixed $value Value of a type the key does not read
     */
    #[DataProvider('patchedKeysWithAForeignValue')]
    public function testAPatchedFieldOfAnotherTypeIsRefused(string $key, mixed $value): void
    {
        $state = RowReaderTestState::fromRow(self::row());

        try {
            $state->applyDiff([$key => $value]);
            $this->fail('The diff was applied although ' . $key . ' held a value of another type');
        } catch (InvalidFormatException $exception) {
            $this->assertStringEndsWith('under key ' . $key, $exception->getMessage());
        }
    }

    public function testADiffTellsALeftAloneNullableFieldFromAClearedOne(): void
    {
        $state = RowReaderTestState::fromRow(self::row());

        $state->applyDiff([RowReaderTestState::weight => 8]);
        $this->assertSame('noted', $state->note);

        $state->applyDiff([RowReaderTestState::note => null]);
        $this->assertNull($state->note);
    }

    /**
     * @param array<string, mixed> $overrides Keys to replace in the well-formed row
     * @return array<string, mixed> Row the test state is built from
     */
    private static function row(array $overrides = []): array
    {
        return array_replace([
            RowReaderTestState::id => 'row-1',
            RowReaderTestState::name => 'first',
            RowReaderTestState::weight => 7,
            RowReaderTestState::ratio => 1.5,
            RowReaderTestState::active => true,
            RowReaderTestState::meta => ['shard' => 'a'],
            RowReaderTestState::tags => ['alpha', 'beta'],
            RowReaderTestState::note => 'noted',
            RowReaderTestState::retries => 3,
            RowReaderTestState::score => 0.25,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $row Row the test state is offered
     * @param string $key Key the refusal is expected to name
     */
    private function assertRefusedRow(array $row, string $key): void
    {
        try {
            RowReaderTestState::fromRow($row);
            $this->fail('The row was accepted although ' . $key . ' did not arrive readable');
        } catch (InvalidFormatException $exception) {
            $this->assertStringEndsWith('under key ' . $key, $exception->getMessage());
        }
    }
}

/**
 * A state carrying one field per reader the base class offers.
 *
 * The row half of it is read by the required and optional readers, the diff half by the
 * patch readers, so a single instance shows what each family does with the same key.
 */
final class RowReaderTestState extends RtState
{
    public const string id = 'id';
    public const string name = 'name';
    public const string weight = 'weight';
    public const string ratio = 'ratio';
    public const string active = 'active';
    public const string meta = 'meta';
    public const string tags = 'tags';
    public const string note = 'note';
    public const string retries = 'retries';
    public const string score = 'score';

    private(set) string $id = '';

    public string $name = '';

    public int $weight = 0;

    public float $ratio = 0.0;

    public bool $active = false;

    /**
     * @var array<string, mixed> Free-form block the row carries as a whole
     */
    public array $meta = [];

    /**
     * @var list<string> Labels the row carries in order
     */
    public array $tags = [];

    public ?string $note = null;

    public ?int $retries = null;

    public ?float $score = null;

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When a field the state is built from did not arrive readable
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->name = self::requireString($row, self::name);
        $instance->weight = self::requireInt($row, self::weight);
        $instance->ratio = self::requireFloat($row, self::ratio);
        $instance->active = self::requireBool($row, self::active);
        $instance->meta = self::requireArray($row, self::meta);
        $instance->tags = self::requireStringList($row, self::tags);
        $instance->note = self::optionalString($row, self::note);
        $instance->retries = self::optionalInt($row, self::retries);
        $instance->score = self::optionalFloat($row, self::score);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds a value of another type
     */
    public function applyDiff(array $diff): void
    {
        $this->name = self::patchString($diff, self::name, $this->name);
        $this->weight = self::patchInt($diff, self::weight, $this->weight);
        $this->ratio = self::patchFloat($diff, self::ratio, $this->ratio);
        $this->active = self::patchBool($diff, self::active, $this->active);
        $this->meta = self::patchArray($diff, self::meta, $this->meta);
        $this->tags = self::patchStringList($diff, self::tags, $this->tags);
        $this->note = self::patchOptionalString($diff, self::note, $this->note);
        $this->retries = self::patchOptionalInt($diff, self::retries, $this->retries);
        $this->score = self::patchOptionalFloat($diff, self::score, $this->score);
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::weight => $this->weight,
            self::ratio => $this->ratio,
            self::active => $this->active,
            self::meta => $this->meta,
            self::tags => $this->tags,
            self::note => $this->note,
            self::retries => $this->retries,
            self::score => $this->score,
        ];
    }
}
