<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\StatusSilencePeriod;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Hilos;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real committed archive writes through the production owner; no publisher or rollback-root simulation. */
final class StatusSilencePeriodsIntegrationTest extends FrameworkIntegrationTestCase
{
    private StatusWorksEnvironment $environment;

    /** Uses the existing production Status fixture and its declared ownership. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->environment = new StatusWorksEnvironment();
    }

    /** Releases the production fixture before the framework connection. */
    protected function tearDown(): void
    {
        try {
            if (isset($this->environment)) {
                $this->environment->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testClosedSignedIntervalReplaysWithoutChangingThePersistedRow(): void
    {
        $this->environment->asOwner(function (): void {
            $period = Hilos::$db->statusSilencePeriods->actions->import(str_repeat('a', 32), -2000, -1000);
            $before = Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows();
            self::assertCount(1, $before);
            self::assertSame(-2000, (int)$before[0]['started_at']);
            self::assertSame(-1000, (int)$before[0]['ended_at']);
            self::assertSame($period->id, Hilos::$db->statusSilencePeriods->findByPublicId($period->publicId)->id);
            self::assertSame([
                'id' => $period->id, 'publicId' => str_repeat('a', 32), 'startedAt' => -2000, 'endedAt' => -1000,
            ], Hilos::$db->statusSilencePeriods[$period->id]->toArray());
            self::assertSame($period->id,
                Hilos::$db->statusSilencePeriods->actions->import($period->publicId, -2000, -1000)->id);
            foreach ([[-2001, -1000], [-2000, -999]] as [$start, $end]) {
                try {
                    Hilos::$db->statusSilencePeriods->actions->import($period->publicId, $start, $end);
                    self::fail('An imported identity must keep both interval boundaries');
                } catch (ValidationException) {
                    self::assertSame($before, Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
                }
            }
            self::assertNull(Hilos::$db->statusSilencePeriods->findByPublicId(str_repeat('f', 32)));
            $crossing = Hilos::$db->statusSilencePeriods->actions->import(str_repeat('b', 32), -1, 0);
            self::assertSame(0, $crossing->endedAt);
        });
    }

    /** @return iterable<string, array{string, int, int}> Invalid identities and nonclosed intervals */
    public static function invalidImports(): iterable
    {
        yield 'empty identity' => ['', 0, 1];
        yield 'short identity' => [str_repeat('a', 31), 0, 1];
        yield 'uppercase identity' => [str_repeat('A', 32), 0, 1];
        yield 'nonhex identity' => [str_repeat('g', 32), 0, 1];
        yield 'identity newline' => [str_repeat('a', 32) . "\n", 0, 1];
        yield 'empty interval' => [str_repeat('a', 32), -1, -1];
        yield 'reversed interval' => [str_repeat('a', 32), 1, 0];
    }

    /**
     * @param string $publicId Attempted identity
     * @param int $startedAt Attempted start
     * @param int $endedAt Attempted end
     */
    #[DataProvider('invalidImports')]
    public function testInvalidImportLeavesNoRow(string $publicId, int $startedAt, int $endedAt): void
    {
        $this->environment->asOwner(function () use ($publicId, $startedAt, $endedAt): void {
            try {
                Hilos::$db->statusSilencePeriods->actions->import($publicId, $startedAt, $endedAt);
                self::fail('Invalid imports must refuse before persistence');
            } catch (ValidationException) {
                self::assertSame([], Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
            }
        });
    }

    public function testRetentionIsStrictAndKeysetPortionsStayDetachedFromTheLazySource(): void
    {
        $this->environment->asOwner(function (): void {
            // Independent SQL fixtures keep the source mirror cold and deliberately reverse end-time order.
            for ($index = 0; $index < 35; ++$index) {
                Database::sql('INSERT INTO `hilos_status_silence_period` (`public_id`, `started_at`, `ended_at`) VALUES (?, ?, ?)',
                    [str_pad(dechex($index), 32, '0', STR_PAD_LEFT), -1000, 1000 - $index]);
            }
            Database::sql('INSERT INTO `hilos_status_silence_period` (`public_id`, `started_at`, `ended_at`) VALUES (?, ?, ?)',
                [str_repeat('e', 32), -2000, -1000]);
            self::assertCount(0, Hilos::$db->statusSilencePeriods->intersecting(-1000, null, 0));
            self::assertCount(0, Hilos::$db->statusSilencePeriods->intersecting(-1000, null, -1));
            $first = Hilos::$db->statusSilencePeriods->intersecting(-1000, null, 999);
            self::assertCount(32, $first);
            self::assertSame(range(1, 32), array_map(static fn ($period): int => $period->id, iterator_to_array($first, false)));
            $second = Hilos::$db->statusSilencePeriods->intersecting(-1000, $first->last()->id, 32);
            self::assertCount(3, $second);
            self::assertSame([33, 34, 35], array_map(static fn ($period): int => $period->id, iterator_to_array($second, false)));
            self::assertCount(0, Hilos::$db->statusSilencePeriods->intersecting(-1000, $second->last()->id, 32));
            self::assertCount(0, Hilos::$db->statusSilencePeriods->intersecting(1000, null, 32));
            self::assertCount(1, Hilos::$db->statusSilencePeriods->intersecting(999, null, 32));
            $repeat = Hilos::$db->statusSilencePeriods->intersecting(-1000, null, 1);
            self::assertNotSame($first->first(), $repeat->first());
            self::assertNotSame($first->first()->getObject(), $repeat->first()->getObject());
            self::assertNotSame($first->first()->getObject(), Hilos::$db->statusSilencePeriods[$first->first()->id]->getObject());
            try {
                iterator_to_array(Hilos::$db->statusSilencePeriods);
                self::fail('Portion reads must not make the source complete');
            } catch (CollectionNotFullyLoadedException) {
                self::assertCount(36, Database::sql('SELECT `id` FROM `hilos_status_silence_period`')->rows());
            }
        });
    }

    public function testOnlyTheOwnerMayImportAndRemoveAKnownItem(): void
    {
        $id = $this->environment->asOwner(fn (): int =>
            Hilos::$db->statusSilencePeriods->actions->import(str_repeat('c', 32), 0, 1)->id);
        ExecutionContext::run(new ExecutionFrame(agentId: 'unrelated-reader'), function () use ($id): void {
            try {
                Hilos::$db->statusSilencePeriods->actions->import(str_repeat('d', 32), 0, 1);
                self::fail('A nonowner must not append history');
            } catch (CreateNotAllowedException) {
                self::assertCount(1, Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
            }
            try {
                Hilos::$db->statusSilencePeriods[$id]->actions->delete();
                self::fail('A nonowner must not remove history');
            } catch (WriteNotAllowedException) {
                self::assertCount(1, Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
            }
        });
        $this->environment->asOwner(function () use ($id): void {
            Hilos::$db->statusSilencePeriods[$id]->actions->delete();
            self::assertArrayNotHasKey($id, Hilos::$db->statusSilencePeriods);
            self::assertNull(Hilos::$db->statusSilencePeriods->findByPublicId(str_repeat('c', 32)));
            self::assertSame([], Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
        });
    }

    public function testSchemaAndNonpersonalVerdictMatchTheMountedProductionChain(): void
    {
        self::assertSame([], EntitySchemaAudit::audit([StatusSilencePeriod::class]));
        self::assertSame(Entity::SET_STANDALONE, StatusSilencePeriod::_setVia);
        self::assertFalse(StatusSilencePeriod::_setRoot);
        self::assertFalse(StatusSilencePeriod::_journaled);
        self::assertSame([], StatusSilencePeriod::_pii);
        self::assertSame(StatusSilencePeriod::_columns, StatusSilencePeriod::_piiNotPersonal);
        $schema = LiveSchemaReader::read(0);
        AnonymizationCoverageValidator::validateLiveSchema(PiiRegistry::collect(), [
            0 => [StatusSilencePeriod::_table => $schema[StatusSilencePeriod::_table]],
        ]);
    }
}
