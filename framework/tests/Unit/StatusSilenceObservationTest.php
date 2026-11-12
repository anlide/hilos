<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Status\Exception\StatusException;
use Hilos\Status\StatusPublicJson;
use Hilos\Status\StatusPublicSilencePeriod;
use Hilos\Status\StatusSilenceObservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Detached silence facts never derive a boundary from a clock or a serialization attempt. */
final class StatusSilenceObservationTest extends TestCase
{
    public function testSignedBoundariesAndIdentitySurviveCompletionAndSerialization(): void
    {
        $open = StatusSilenceObservation::start(PHP_INT_MIN);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $open->id);
        self::assertSame(PHP_INT_MIN, $open->startedAt);
        self::assertNull($open->endedAt);
        $closed = $open->complete(PHP_INT_MAX);
        self::assertSame($open->id, $closed->id);
        self::assertSame($open->startedAt, $closed->startedAt);
        self::assertSame(PHP_INT_MAX, $closed->endedAt);
        self::assertNull($open->endedAt, 'A trial completion leaves the pending value open.');
        self::assertSame($closed, $closed->complete(PHP_INT_MAX));
        self::assertSame($closed->toArray(), StatusSilenceObservation::fromJson(json_encode($closed->toArray(), JSON_THROW_ON_ERROR))->toArray());
        $public = $closed->toPublicPeriod();
        self::assertSame(['id' => $open->id, 'startedAt' => PHP_INT_MIN, 'endedAt' => PHP_INT_MAX], $public->toArray());
        self::assertSame($public->toArray(), StatusPublicSilencePeriod::fromArray($public->toArray())->toArray());
        self::assertSame(-1, StatusPublicJson::signedInteger(-1));
        $this->expectException(StatusException::class);
        StatusPublicJson::integer(-1);
    }

    public function testOpenObservationCannotBecomeAPublicPeriod(): void
    {
        $this->expectException(StatusException::class);
        StatusSilenceObservation::start(-1000)->toPublicPeriod();
    }

    public function testClosedObservationCannotChangeItsConfirmedEnd(): void
    {
        $this->expectException(StatusException::class);
        StatusSilenceObservation::start(-1000)->complete(-1)->complete(0);
    }

    /** @param string $json Malformed private transit */
    #[DataProvider('invalidTransits')]
    public function testTransitRefusesMalformedShapesAndBounds(string $json): void
    {
        $this->expectException(StatusException::class);
        StatusSilenceObservation::fromJson($json);
    }

    /** @return iterable<string, array{string}> Shape, type, identity and signed ordering violations */
    public static function invalidTransits(): iterable
    {
        yield 'broken JSON' => ['{broken'];
        yield 'root list' => ['[]'];
        $valid = ['formatVersion' => 1, 'period' => ['id' => str_repeat('a', 32), 'startedAt' => -1000, 'endedAt' => null]];
        foreach ([null, true, '1', 1.0, 2] as $version) {
            $invalid = $valid;
            $invalid['formatVersion'] = $version;
            yield 'version ' . json_encode($version) => [json_encode($invalid, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }
        foreach (['formatVersion', 'period'] as $key) {
            $invalid = $valid;
            unset($invalid[$key]);
            yield 'missing ' . $key => [json_encode($invalid, JSON_THROW_ON_ERROR)];
        }
        $invalid = $valid;
        $invalid['nodeId'] = 'private-node';
        yield 'extra envelope field' => [json_encode($invalid, JSON_THROW_ON_ERROR)];
        $invalid = $valid;
        $invalid['period'] = [];
        yield 'period list' => [json_encode($invalid, JSON_THROW_ON_ERROR)];
        foreach (['id', 'startedAt', 'endedAt'] as $key) {
            $invalid = $valid;
            unset($invalid['period'][$key]);
            yield 'missing period ' . $key => [json_encode($invalid, JSON_THROW_ON_ERROR)];
        }
        foreach ([
            'short id' => ['id', str_repeat('a', 31)],
            'uppercase id' => ['id', str_repeat('A', 32)],
            'internal id' => ['id', 123],
            'private field' => ['nodeId', 'private-node'],
            'null start' => ['startedAt', null],
            'string start' => ['startedAt', '-1000'],
            'float start' => ['startedAt', -1000.0],
            'boolean end' => ['endedAt', false],
            'string end' => ['endedAt', '-1'],
            'float end' => ['endedAt', -1.0],
            'empty interval' => ['endedAt', -1000],
            'reversed interval' => ['endedAt', -1001],
        ] as $label => [$key, $value]) {
            $invalid = $valid;
            $invalid['period'][$key] = $value;
            yield $label => [json_encode($invalid, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }
        yield 'integer overflow' => [
            '{"formatVersion":1,"period":{"id":"' . str_repeat('a', 32) . '","startedAt":-1,"endedAt":9223372036854775808}}',
        ];
    }

    /** @param array<string, mixed> $fields Malformed closed public fields */
    #[DataProvider('invalidPublicPeriods')]
    public function testPublicPeriodRequiresAnExactClosedShape(array $fields): void
    {
        $this->expectException(StatusException::class);
        StatusPublicSilencePeriod::fromArray($fields);
    }

    /** @return iterable<string, array{array<string, mixed>}> Public shape and closed interval violations */
    public static function invalidPublicPeriods(): iterable
    {
        $valid = ['id' => str_repeat('a', 32), 'startedAt' => -1000, 'endedAt' => -1];
        foreach (['id', 'startedAt', 'endedAt'] as $key) {
            $invalid = $valid;
            unset($invalid[$key]);
            yield 'missing ' . $key => [$invalid];
        }
        foreach ([
            'open end' => ['endedAt', null],
            'float end' => ['endedAt', -1.0],
            'string start' => ['startedAt', '-1000'],
            'equal end' => ['endedAt', -1000],
            'earlier end' => ['endedAt', -1001],
            'private field' => ['nodeId', 'private-node'],
            'invalid identity' => ['id', 'internal-123'],
        ] as $label => [$key, $value]) {
            $invalid = $valid;
            $invalid[$key] = $value;
            yield $label => [$invalid];
        }
    }
}
