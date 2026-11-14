<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Fs\FsPath;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicSnapshotBuilder;
use Hilos\Status\StatusSilenceObservation;
use Hilos\Status\StatusSilenceObservationStore;
use Hilos\Status\StatusSnapshot;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Utils\Helpers\TimeHelper;

/** Real commits, owner restart and replacement-source reads on an isolated public volume. */
final class StatusSilenceStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private StatusWorksEnvironment $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new StatusWorksEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            $this->env->close();
        } finally {
            parent::tearDown();
        }
    }

    public function testClosedTransitImportsOnceAndThePublicArchiveComesFromTheCommittedRows(): void
    {
        $this->env->recipient('reader@example.test');
        $fact = StatusSilenceObservation::start(TimeHelper::nowMs() - 200000)->complete(TimeHelper::nowMs() - 1000);
        new StatusSilenceObservationStore()->save($fact);
        $this->restart();
        $frames = $this->settle();
        self::assertSame([], array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::HILOS_MAIL_SEND)));
        $rows = Database::sql('SELECT public_id, started_at, ended_at FROM hilos_status_silence_period')->rows();
        self::assertCount(1, $rows);
        self::assertSame($fact->id, $rows[0]['public_id']);
        self::assertSame($fact->startedAt, (int)$rows[0]['started_at']);
        self::assertSame($fact->endedAt, (int)$rows[0]['ended_at']);
        self::assertSame([$fact->toPublicPeriod()->toArray()], $this->snapshot()->toArray()['silencePeriods']);
        self::assertSame([], new StatusSilenceObservationStore()->discover());
        $this->restart();
        $this->settle();
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_silence_period')->rows());
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_incident_update')->rows());

    }

    public function testPendingGapSurvivesFreezeAndRestartWithoutDatabaseAccess(): void
    {
        $this->settle();
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->settle();
        $stale = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        $id = $stale->payload['silencePeriodId'];
        $start = $stale->payload['aliveAt'];
        $db = Hilos::$db;
        $settings = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->restart();
            $this->settle();
            self::assertSame($id, $this->snapshot()->silencePeriods[0]->id);
            self::assertSame($start, $this->snapshot()->silencePeriods[0]->startedAt);
            self::assertSame([], array_unique(array_diff(array_column($this->snapshot()->toArray()['components'], 'state'), ['maintenance'])));
            self::assertNotEmpty(new StatusSilenceObservationStore()->discover());
        } finally {
            Hilos::$db = $db;
            Hilos::$setting = $settings;
        }
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_silence_period')->rows());
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle();
        self::assertSame($id, Database::sql('SELECT public_id FROM hilos_status_silence_period')->rows()[0]['public_id']);
    }

    public function testCleanupIsOwnedIdempotentAndRefusesHeldAndFrozenTargets(): void
    {
        $this->settle();
        $first = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        $id = $first->payload['silencePeriodId'];
        $second = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        self::assertSame($first->payload, $second->payload);
        self::assertFalse($this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $id])->isOk());
        $now = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        self::assertTrue($now->isOk());
        self::assertSame($now->payload['aliveAt'], $this->snapshot()->silencePeriods[0]->endedAt);
        $foreign = StatusSilenceObservation::start(TimeHelper::nowMs() - 300000)->complete(TimeHelper::nowMs() - 250000);
        $this->env->asOwner(fn () => Hilos::$db->statusSilencePeriods->actions->import($foreign->id, $foreign->startedAt, $foreign->endedAt));
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        self::assertFalse($this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $id])->isOk());
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $removed = $this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $id]);
        self::assertTrue($removed->isOk(), json_encode($removed->payload));
        self::assertSame(['publicId' => $id], $removed->payload);
        self::assertSame([$foreign->id], array_column(Database::sql('SELECT public_id FROM hilos_status_silence_period')->rows(), 'public_id'));
        self::assertSame([$foreign->id], array_column($this->snapshot()->toArray()['silencePeriods'], 'id'));
        self::assertTrue($this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $id])->isOk());
        self::assertSame([], new StatusSilenceObservationStore()->discover());
    }

    public function testCleanupCannotDeleteTheRowWhileItsClosedTransitCannotBeRemoved(): void
    {
        $fact = StatusSilenceObservation::start(TimeHelper::nowMs() - 200000)->complete(TimeHelper::nowMs() - 1000);
        new StatusSilenceObservationStore()->save($fact);
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->restart();
        $this->settle();
        $path = Hilos::$fs->status->getPath() . '/' . StatusSilenceObservationStore::DIRECTORY . '/' . $fact->id . '.json';
        FsPath::write($path, 'damaged after bounded recovery');
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $refusal = $this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $fact->id]);
        self::assertFalse($refusal->isOk());
        self::assertSame($fact->id, Database::sql('SELECT public_id FROM hilos_status_silence_period')->rows()[0]['public_id']);
        self::assertSame('damaged after bounded recovery', FsPath::read($path));
        FsPath::write($path, json_encode($fact->toArray(), JSON_THROW_ON_ERROR));
        self::assertTrue($this->command(CliCommands::STATUS_TEST_SILENCE_REMOVE, ['publicId' => $fact->id])->isOk());
        $this->settle();
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_silence_period')->rows());
        self::assertSame([], new StatusSilenceObservationStore()->discover());
    }

    public function testRollbackLeavesClosedTransitForTheOrdinaryOwnerRecovery(): void
    {
        $fact = StatusSilenceObservation::start(TimeHelper::nowMs() - 200000)->complete(TimeHelper::nowMs() - 1000);
        new StatusSilenceObservationStore()->save($fact);
        $this->env->asOwner(function () use ($fact): void {
            Database::transactionStart();
            Hilos::$db->statusSilencePeriods->actions->import($fact->id, $fact->startedAt, $fact->endedAt);
            Database::transactionRollback();
        });
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_silence_period')->rows());
        $this->restart();
        $this->settle();
        self::assertSame($fact->id, Database::sql('SELECT public_id FROM hilos_status_silence_period')->rows()[0]['public_id']);
    }

    public function testReplacedDatabaseDoesNotResurrectAnAlreadyImportedPublicArchive(): void
    {
        $this->settle();
        $stale = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        self::assertTrue($this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW)->isOk());
        $this->settle();
        self::assertCount(1, $this->snapshot()->silencePeriods);
        self::assertSame([], new StatusSilenceObservationStore()->discover());
        // The restored source lacks a row written after the backup; the public file is deliberately still old.
        Database::sql('DELETE FROM hilos_status_silence_period WHERE public_id = ?', [$stale->payload['silencePeriodId']]);
        Hilos::$db->forgetLoadedDataForTest();
        $this->restart();
        $this->settle();
        self::assertSame([], $this->snapshot()->silencePeriods);
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_silence_period')->rows());
    }

    public function testBoundedArchiveReadOrdersSignedStartsAndPreservesLongCrossingIntervals(): void
    {
        $now = TimeHelper::nowMs();
        $window = 90 * 86400000;
        $this->env->asOwner(function () use ($now, $window): void {
            for ($index = 0; $index < 35; ++$index) {
                Hilos::$db->statusSilencePeriods->actions->import(str_pad(dechex($index), 32, '0', STR_PAD_LEFT), -1000, $now - $index);
            }
            Hilos::$db->statusSilencePeriods->actions->import(str_repeat('f', 32), -2000, $now - $window);
        });
        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'bounded', $now, 30, 120, ['service' => $now, 'mail' => $now]);
        $steps = 0;
        while (!$builder->isReady() && ++$steps < 100) {
            $builder->advance();
        }
        self::assertTrue($builder->isReady());
        self::assertGreaterThan(5, $steps);
        self::assertCount(35, $builder->getSnapshot()->silencePeriods);
        self::assertSame(-1000, $builder->getSnapshot()->silencePeriods[0]->startedAt);
        self::assertSame(str_repeat('0', 32), $builder->getSnapshot()->silencePeriods[0]->id);
    }

    private function restart(): void
    {
        $this->env->asOwner(fn () => $this->env->owner->onStop());
        $this->env->drain();
        $this->env->asOwner(fn () => $this->env->owner->onStart());
    }

    /** @return list<SignalDTO> Owner frames from a bounded settling cycle */
    private function settle(): array
    {
        for ($tick = 0; $tick < 100; ++$tick) {
            $this->env->tick();
        }
        return $this->env->drain();
    }

    private function snapshot(): StatusPublicSnapshot
    {
        return StatusPublicSnapshot::fromJson(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
    }

    /** @param array<string, mixed> $payload Command request fields */
    private function command(string $name, array $payload = []): CommandReplyDTO
    {
        $this->env->drain();
        $this->env->asOwner(fn () => $this->env->owner->onSignalCommand(new CommandRequestDTO('storage', $name, $payload), 'cli', $name));
        $frames = $this->env->drain();
        for ($tick = 0; $tick < 100; ++$tick) {
            $replies = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
                $frame->signalType->getType() === SignalTypeConstants::COMMAND_REPLY));
            if ($replies !== []) {
                self::assertCount(1, $replies);
                self::assertFalse(Database::hasOpenTransactions());
                return $replies[0]->data;
            }
            $this->env->tick();
            $frames = array_merge($frames, $this->env->drain());
        }
        self::fail('Owner did not answer within bounded test ticks.');
    }
}
