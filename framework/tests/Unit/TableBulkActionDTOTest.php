<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableBulkActionDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shape of a bulk action request: one target of two, and never anything else.
 */
final class TableBulkActionDTOTest extends TestCase
{
    public function testNamedRowsTravelInTheOrderTheyArrived(): void
    {
        $restored = BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => 'sessions',
            TableConstants::PAYLOAD_KEY_ROW_KEYS => ['c', 'a', 'b'],
        ]);

        $this->assertSame('sessions', $restored->tableKey);
        $this->assertSame(['c', 'a', 'b'], $restored->rowKeys);
        $this->assertNull($restored->filter);
        $this->assertArrayNotHasKey(SignalPayloadConstants::FIELD_FILTER, $restored->toArray());
    }

    public function testAConditionTravelsWholeAndKeepsTheSearchTermInsideIt(): void
    {
        $filter = [TableConstants::FILTER_KEY_SEARCH => 'sam', 'role' => 'admin'];

        $restored = BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => 'users',
            SignalPayloadConstants::FIELD_FILTER => $filter,
        ]);

        $this->assertSame($filter, $restored->filter);
        $this->assertNull($restored->rowKeys);
        $this->assertArrayNotHasKey(TableConstants::PAYLOAD_KEY_ROW_KEYS, $restored->toArray());
    }

    public function testARequestNamingBothTargetsIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => 'users',
            TableConstants::PAYLOAD_KEY_ROW_KEYS => ['a'],
            SignalPayloadConstants::FIELD_FILTER => ['role' => 'admin'],
        ]);
    }

    public function testARequestNamingNeitherTargetIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([TableConstants::PAYLOAD_KEY_TABLE_KEY => 'users']);
    }

    public function testAnEmptyListOfRowsIsRefusedRatherThanRunOverNothing(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => 'users',
            TableConstants::PAYLOAD_KEY_ROW_KEYS => [],
        ]);
    }

    public function testAnEmptyTableKeyIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => '',
            TableConstants::PAYLOAD_KEY_ROW_KEYS => ['a'],
        ]);
    }

    public function testAMissingTableKeyIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([TableConstants::PAYLOAD_KEY_ROW_KEYS => ['a']]);
    }

    public function testARowKeyThatIsNotAStringIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        BulkDeleteTestActionDTO::fromArray([
            TableConstants::PAYLOAD_KEY_TABLE_KEY => 'users',
            TableConstants::PAYLOAD_KEY_ROW_KEYS => ['a', 17],
        ]);
    }

    public function testTheConcreteActionKeepsItsOwnName(): void
    {
        $dto = new BulkDeleteTestActionDTO('users', ['a'], null);

        $this->assertSame(BulkDeleteTestActionDTO::ACTION, $dto->getAction());
    }
}

/**
 * A concrete bulk action, standing in for the one a page declares in its ACTIONS.
 *
 * The framework fixes the shape of the request and leaves the name to the page, so the abstract
 * payload cannot be exercised without one of these.
 */
final class BulkDeleteTestActionDTO extends TableBulkActionDTO
{
    /** Name this stand-in answers to. */
    public const string ACTION = 'bulkDelete';

    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return self::ACTION;
    }
}
