<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Error;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Unit coverage of complete placement snapshot codecs before their peer transport is connected (HIL-446). */
final class PlacementSnapshotTest extends TestCase
{
    /** @return iterable<string, array{PlacementRecord}> Valid records spanning every state and nullable field */
    public static function validRecords(): iterable
    {
        yield 'placing' => [new PlacementRecord('worker', '7', 'node-a', PlacementState::Placing)];
        yield 'started singleton' => [new PlacementRecord('singleton', null, 'node-b', PlacementState::Started)];
        yield 'failed with historical host and diagnosis' => [
            new PlacementRecord('worker', '8', 'node-c', PlacementState::Failed, 'no capable node', [
                PlacementRefusal::unmetCapabilities('node-a', ['gpu', 'render']),
                PlacementRefusal::noDeclaredCapacity('node-b'),
                PlacementRefusal::insufficientCapacity('node-c', [
                    'slots' => ['cost' => 1.0, 'free' => 0.0],
                    'ram' => ['cost' => 2.5, 'free' => 1.25],
                ]),
            ]),
        ];
        yield 'failed without host or diagnosis' => [new PlacementRecord('worker', '9', null, PlacementState::Failed)];
        yield 'unplaced without host' => [
            new PlacementRecord('worker', '10', null, PlacementState::Unplaced, 'no online nodes', [PlacementRefusal::noOnlineNodes()]),
        ];
        yield 'unplaced with historical host and null reason' => [
            new PlacementRecord('worker', '11', 'node-c', PlacementState::Unplaced, null, [PlacementRefusal::noDeclaredCapacity('node-c')]),
        ];
        yield 'stopped confirmed' => [new PlacementRecord('worker', '12', null, PlacementState::Stopped)];
        yield 'stopped awaiting acknowledgement' => [new PlacementRecord('worker', '13', 'node-a', PlacementState::Stopped)];
        yield 'refused' => [new PlacementRecord('worker', '14', 'node-b', PlacementState::Refused)];
        yield 'numeric strings including zero' => [new PlacementRecord('0', '0', '0', PlacementState::Started)];
        yield 'numeric node in refusal' => [
            new PlacementRecord('worker', '15', '123', PlacementState::Failed, null, [PlacementRefusal::noDeclaredCapacity('0')]),
        ];
    }

    /** @param PlacementRecord $record Record whose fields must survive the associative JSON boundary */
    #[DataProvider('validRecords')]
    public function testEveryRecordRoundTripsThroughJson(PlacementRecord $record): void
    {
        $parsed = PlacementRecord::fromArray(self::wireArray($record->toArray()));

        $this->assertNotSame($record, $parsed);
        $this->assertTrue($record->sameAs($parsed));
        $this->assertSame($record->agentId(), $parsed->agentId());
        $this->assertSame(self::wireArray($record->toArray()), self::wireArray($parsed->toArray()));
    }

    public function testRecordWriterIncludesEveryKeyAndKeepsEmptyShortfallsAnObject(): void
    {
        $record = new PlacementRecord('worker', null, null, PlacementState::Unplaced, null, [PlacementRefusal::noOnlineNodes()]);
        $payload = $record->toArray();

        $this->assertSame(['agentType', 'agentIndex', 'nodeId', 'state', 'reason', 'refusals'], array_keys($payload));
        $this->assertSame([
            'agentType' => 'worker',
            'agentIndex' => null,
            'nodeId' => null,
            'state' => 'unplaced',
            'reason' => null,
            'refusals' => [[
                'nodeId' => null,
                'reasonCode' => 'no_online_nodes',
                'missingCapabilities' => [],
                'shortfalls' => [],
            ]],
        ], self::wireArray($payload));
        $this->assertIsObject($payload['refusals'][0]['shortfalls']);
        $this->assertStringContainsString('"shortfalls":{}', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testSnapshotRoundTripsEveryStateAndPreservesRecordOrder(): void
    {
        $records = array_map(static fn(array $case): PlacementRecord => $case[0], iterator_to_array(self::validRecords()));
        $snapshot = new PlacementSnapshot('123', 5, 17, array_values($records));
        $payload = self::wireArray($snapshot->toArray());
        $parsed = PlacementSnapshot::fromArray($payload);

        $this->assertSame(['leaderNodeId', 'term', 'revision', 'records'], array_keys($payload));
        $this->assertSame('123', $parsed->leaderNodeId);
        $this->assertSame(5, $parsed->term);
        $this->assertSame(17, $parsed->revision);
        $this->assertCount(count($snapshot->records), $parsed->records);
        foreach ($snapshot->records as $position => $record) {
            $this->assertTrue($record->sameAs($parsed->records[$position]), $record->agentId());
        }
        $this->assertSame($payload, self::wireArray($parsed->toArray()));
    }

    public function testEmptySnapshotIsAValueDistinctFromAnAbsentCopy(): void
    {
        $copy = null;
        $this->assertNull($copy);

        $copy = PlacementSnapshot::fromArray(self::wireArray(new PlacementSnapshot('0', 0, 0, [])->toArray()));

        $this->assertInstanceOf(PlacementSnapshot::class, $copy);
        $this->assertSame(['leaderNodeId' => '0', 'term' => 0, 'revision' => 0, 'records' => []], $copy->toArray());
    }

    public function testSnapshotRecordsCannotBeMutated(): void
    {
        $snapshot = new PlacementSnapshot('leader', 0, 0, []);

        $this->expectException(Error::class);
        $snapshot->records[] = new PlacementRecord('worker', null, null, PlacementState::Unplaced);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed required fields and nested refusals */
    public static function malformedRecords(): iterable
    {
        $valid = self::recordPayload();
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield "missing {$field}" => [$missing];
        }

        foreach ([null, false, 7, 1.5, [], '', '  '] as $position => $value) {
            yield "bad agent type {$position}" => [array_replace($valid, ['agentType' => $value])];
        }
        foreach ([false, 7, 1.5, [], '', '  '] as $position => $value) {
            yield "bad index {$position}" => [array_replace($valid, ['agentIndex' => $value])];
            yield "bad node {$position}" => [array_replace($valid, ['nodeId' => $value])];
        }
        foreach ([null, false, 7, [], '', 'unknown'] as $position => $value) {
            yield "bad state {$position}" => [array_replace($valid, ['state' => $value])];
        }
        foreach ([false, 7, 1.5, []] as $position => $value) {
            yield "bad reason {$position}" => [array_replace($valid, ['reason' => $value])];
        }
        foreach ([null, false, 'none', ['first' => []], [1 => []]] as $position => $value) {
            yield "bad refusals list {$position}" => [array_replace($valid, ['refusals' => $value])];
        }

        foreach (['placing', 'started', 'refused'] as $state) {
            yield "null host for {$state}" => [array_replace($valid, ['state' => $state, 'nodeId' => null])];
        }
        foreach (['placing', 'started', 'stopped', 'refused'] as $state) {
            yield "reason for {$state}" => [array_replace($valid, ['state' => $state, 'reason' => 'failure'])];
            yield "empty reason for {$state}" => [array_replace($valid, ['state' => $state, 'reason' => ''])];
            yield "refusals for {$state}" => [array_replace($valid, [
                'state' => $state,
                'refusals' => [self::wireArray(PlacementRefusal::noDeclaredCapacity('node-a')->toArray())],
            ])];
        }

        $refusal = self::wireArray(PlacementRefusal::insufficientCapacity('node-a', ['slots' => ['cost' => 1.0, 'free' => 0.0]])->toArray());
        foreach (array_keys($refusal) as $field) {
            $missing = $refusal;
            unset($missing[$field]);
            yield "refusal missing {$field}" => [array_replace($valid, ['refusals' => [$missing]])];
        }
        foreach ([
            null, 'not an object', [],
            array_replace($refusal, ['reasonCode' => 'unknown']),
            array_replace($refusal, ['nodeId' => 7]),
            array_replace($refusal, ['missingCapabilities' => ['gpu']]),
            array_replace($refusal, ['shortfalls' => []]),
            array_replace($refusal, ['shortfalls' => [['cost' => 1, 'free' => 0]]]),
            array_replace($refusal, ['shortfalls' => ['slots' => ['cost' => '1', 'free' => 0]]]),
            array_replace($refusal, ['shortfalls' => ['slots' => ['cost' => 1]]]),
        ] as $position => $badRefusal) {
            yield "malformed refusal {$position}" => [array_replace($valid, ['refusals' => [$badRefusal]])];
        }
    }

    /** @param array<string, mixed> $payload Malformed record payload */
    #[DataProvider('malformedRecords')]
    public function testRecordRejectsMalformedPayload(array $payload): void
    {
        $this->expectException(PeerTransportException::class);
        PlacementRecord::fromArray(self::wireArray($payload));
    }

    /** @param array<string, mixed> $payload Malformed record among two valid entries */
    #[DataProvider('malformedRecords')]
    public function testOneMalformedRecordRejectsTheWholeSnapshot(array $payload): void
    {
        $wire = [
            'leaderNodeId' => 'leader', 'term' => 1, 'revision' => 3,
            'records' => [
                array_replace(self::recordPayload(), ['agentType' => 'before']),
                $payload,
                array_replace(self::recordPayload(), ['agentType' => 'after']),
            ],
        ];

        $this->expectException(PeerTransportException::class);
        PlacementSnapshot::fromArray(self::wireArray($wire));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid snapshot metadata, record containers and identities */
    public static function malformedSnapshots(): iterable
    {
        $valid = ['leaderNodeId' => 'leader', 'term' => 1, 'revision' => 2, 'records' => [self::recordPayload()]];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield "missing {$field}" => [$missing];
        }
        foreach ([null, '', '  ', 123, false, []] as $position => $value) {
            yield "bad leader {$position}" => [array_replace($valid, ['leaderNodeId' => $value])];
        }
        foreach (['term', 'revision'] as $field) {
            foreach ([null, -1, '1', 1.0, true, []] as $position => $value) {
                yield "bad {$field} {$position}" => [array_replace($valid, [$field => $value])];
            }
        }
        foreach ([null, false, 'none', ['first' => self::recordPayload()], [1 => self::recordPayload()]] as $position => $value) {
            yield "bad records {$position}" => [array_replace($valid, ['records' => $value])];
        }
        foreach ([null, false, 'not an object', 7, [], ['worker', '7']] as $position => $row) {
            yield "bad row {$position}" => [array_replace($valid, ['records' => [self::recordPayload(), $row]])];
        }
        yield 'duplicate indexed agent with different state and node' => [array_replace($valid, ['records' => [
            self::recordPayload(), array_replace(self::recordPayload(), ['state' => 'started', 'nodeId' => 'node-b']),
        ]])];
        yield 'duplicate singleton' => [array_replace($valid, ['records' => [
            array_replace(self::recordPayload(), ['agentIndex' => null]),
            array_replace(self::recordPayload(), ['agentIndex' => null, 'nodeId' => null]),
        ]])];
        yield 'duplicate derived agent id' => [array_replace($valid, ['records' => [
            self::recordPayload(), array_replace(self::recordPayload(), ['agentType' => 'worker:7', 'agentIndex' => null]),
        ]])];
    }

    /** @param array<string, mixed> $payload Malformed full snapshot */
    #[DataProvider('malformedSnapshots')]
    public function testSnapshotRejectsMalformedPayload(array $payload): void
    {
        $this->expectException(PeerTransportException::class);
        PlacementSnapshot::fromArray(self::wireArray($payload));
    }

    /** @return iterable<string, array{string, int, int, array}> Invalid directly constructed snapshots */
    public static function invalidConstruction(): iterable
    {
        yield 'blank leader' => [' ', 0, 0, []];
        yield 'negative term' => ['leader', -1, 0, []];
        yield 'negative revision' => ['leader', 0, -1, []];
        yield 'record map' => ['leader', 0, 0, ['worker' => new PlacementRecord('worker', null, null, PlacementState::Unplaced)]];
        yield 'untyped record' => ['leader', 0, 0, [self::recordPayload()]];
        yield 'duplicate agent' => ['leader', 0, 0, [
            new PlacementRecord('worker', null, null, PlacementState::Unplaced),
            new PlacementRecord('worker', null, null, PlacementState::Stopped),
        ]];
    }

    /**
     * @param string $leaderNodeId Leader id
     * @param int $term Leader term
     * @param int $revision Registry revision
     * @param array $records Records supplied by an internal caller
     */
    #[DataProvider('invalidConstruction')]
    public function testConstructorRejectsInvalidSnapshots(string $leaderNodeId, int $term, int $revision, array $records): void
    {
        $this->expectException(PeerTransportException::class);
        new PlacementSnapshot($leaderNodeId, $term, $revision, $records);
    }

    /** @return array<string, mixed> Valid failure with explicit nullable fields */
    private static function recordPayload(): array
    {
        return ['agentType' => 'worker', 'agentIndex' => '7', 'nodeId' => 'node-a', 'state' => 'failed', 'reason' => null, 'refusals' => []];
    }

    /**
     * Matches PeerDTO's associative JSON decoding, including empty shortfalls objects.
     *
     * @param array<string, mixed> $payload Payload to pass through JSON
     * @return array<string, mixed> Decoded payload
     */
    private static function wireArray(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
    }
}
