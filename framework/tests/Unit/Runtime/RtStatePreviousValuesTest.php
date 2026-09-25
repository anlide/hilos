<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\RtState;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pins the old field values carried by direct runtime state synchronization.
 */
final class RtStatePreviousValuesTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::registerDaemon(PreviousValuesState::COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(PreviousValuesState::COLLECTION);
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testSyncCarriesTheBaselineValuesOfChangedFields(): void
    {
        $state = PreviousValuesState::create('row-1', 'before');
        $state->rename('after');
        $state->sync();

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
        self::assertSame([PreviousValuesState::name => 'after'], $signal->data->row);
        self::assertSame([PreviousValuesState::name => 'before'], $signal->data->previous);
    }
}

final class PreviousValuesState extends RtState
{
    public const string COLLECTION = 'previousValues';
    public const string id = 'id';
    public const string name = 'name';

    private(set) string $id;
    private(set) string $name;

    private function __construct(string $id, string $name)
    {
        parent::__construct();

        $this->id = $id;
        $this->name = $name;
    }

    /**
     * @param string $id Runtime row id
     * @param string $name Initial name
     * @return static New runtime row
     */
    public static function create(string $id, string $name): static
    {
        $state = new static($id, $name);
        $state->markRtSyncBaseline();

        return $state;
    }

    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return self::COLLECTION;
    }

    /**
     * @return string Runtime row id
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated runtime row
     * @throws InvalidFormatException When the row misses a required field
     */
    public static function fromRow(array $row): static
    {
        return static::create(
            self::requireString($row, self::id),
            self::requireString($row, self::name),
        );
    }

    /**
     * @return array<string, mixed> Serialized runtime row
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
        ];
    }

    /**
     * @param string $name New name
     */
    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
