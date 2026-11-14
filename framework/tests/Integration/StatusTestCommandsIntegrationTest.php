<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusTestCommandConstants;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/**
 * The test-only Status commands answered by the real owner over real commits and a real public volume (HIL-1856).
 *
 * Real commits are what this needs: a work start announces after its commit, and the public files
 * are read the way a reader reads them.
 */
final class StatusTestCommandsIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Ticks a pair needs at most to settle; a bounded loop, never a wait. */
    private const int SETTLE_TICKS = 100;

    private StatusWorksEnvironment $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new StatusWorksEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->env)) {
                $this->env->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testHeartbeatCommandsRefuseBeforeTheFirstPairIsPublished(): void
    {
        self::assertFalse(is_file(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->getPath()));
        foreach ([CliCommands::STATUS_TEST_HEARTBEAT_STALE, CliCommands::STATUS_TEST_HEARTBEAT_NOW] as $command) {
            [$reply, $frames] = $this->command($command);
            self::assertFalse($reply->isOk(), $command);
            self::assertSame(StatusPublicFiles::NO_PUBLIC_PAIR, $reply->payload['message'], $command);
            self::assertSame([], $frames, $command);
        }
        [$reply] = $this->command('test:status:unknown');
        self::assertSame('Unknown command: test:status:unknown', $reply->payload['message']);
    }

    public function testStaleAgesTheHeartbeatAndHoldsBothHeartbeatAndContentUntilNow(): void
    {
        $this->settle(fn (): bool => $this->pair() !== null);
        $first = $this->pair();
        $staleMs = Hilos::$env[EnvConstants::STATUS_STALE_AFTER_SECONDS]->int() * 1000 + 1000;

        $before = TimeHelper::nowMs();
        [$reply, $frames] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        $after = TimeHelper::nowMs();
        self::assertTrue($reply->isOk());
        $aged = $reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT];
        self::assertGreaterThanOrEqual($before - $staleMs, $aged);
        self::assertLessThanOrEqual($after - $staleMs, $aged);
        self::assertSame(['schemaVersion' => 4, 'revision' => $first['status']['revision'], 'aliveAt' => $aged],
            $this->pair()['heartbeat']);
        self::assertSame(1, $this->hints($frames));

        $payload = $this->payload(60);
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload)->error);
        for ($tick = 0; $tick < self::SETTLE_TICKS; ++$tick) {
            $this->env->tick();
        }
        self::assertSame(0, $this->hints($this->env->drain()));
        self::assertSame($first['status'], $this->pair()['status'], 'The hold keeps the content');
        self::assertSame($aged, $this->pair()['heartbeat']['aliveAt'], 'The hold keeps the aged heartbeat');

        $before = TimeHelper::nowMs();
        [$reply, $frames] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        $after = TimeHelper::nowMs();
        self::assertTrue($reply->isOk());
        $fresh = $reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT];
        self::assertGreaterThanOrEqual($before, $fresh);
        self::assertLessThanOrEqual($after, $fresh);
        self::assertSame($fresh, $this->pair()['heartbeat']['aliveAt']);
        self::assertSame(1, $this->hints($frames));
        $this->settle(fn (): bool => ($this->pair()['status']['scheduledWorks'][0]['startsAt'] ?? null) === $payload['startsAt']);

        // Settled, a heartbeat asked for now is followed by the next one only a whole interval later.
        $interval = (int)ceil(new StatusPublicFiles()->getHeartbeatIntervalMs());
        [$reply] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        $fresh = $reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT];
        $this->env->tick($fresh + $interval - 1);
        self::assertSame($fresh, $this->pair()['heartbeat']['aliveAt']);
        $this->env->tick($fresh + $interval);
        self::assertSame($fresh + $interval, $this->pair()['heartbeat']['aliveAt']);
    }

    public function testNowUnderAnActiveFreezePublishesAFreshHeartbeatOverTheAgedOne(): void
    {
        $this->settle(fn (): bool => $this->pair() !== null);
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->settle(fn (): bool => array_unique(array_column($this->pair()['status']['components'], 'state')) === ['maintenance']);
        [$reply] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        $aged = $reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT];
        $this->env->tick();
        self::assertSame(0, $this->hints($this->env->drain()));
        self::assertSame($aged, $this->pair()['heartbeat']['aliveAt']);

        $before = TimeHelper::nowMs();
        [$reply, $frames] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        self::assertTrue($reply->isOk());
        $fresh = $reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT];
        self::assertGreaterThanOrEqual($before, $fresh);
        self::assertGreaterThan($aged, $fresh);
        self::assertSame($fresh, $this->pair()['heartbeat']['aliveAt']);
        self::assertSame(['maintenance'], array_unique(array_column($this->pair()['status']['components'], 'state')));
        self::assertSame(1, $this->hints($frames));
    }

    public function testNowBeforeTheTickThatSeesAFreezePublishesTheMaintenancePairTheTickWould(): void
    {
        $this->settle(fn (): bool => $this->pair() !== null);
        $first = $this->pair();
        // The announcement leaves a database read pending; the freeze that follows must not let it through.
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $this->payload(60))->error);
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);

        [$reply, $frames] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        self::assertTrue($reply->isOk(), json_encode($reply->payload, JSON_THROW_ON_ERROR));
        $pair = $this->pair();
        self::assertSame($reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT], $pair['heartbeat']['aliveAt']);
        self::assertNotSame($first['status']['revision'], $pair['status']['revision']);
        self::assertSame(['maintenance'], array_unique(array_column($pair['status']['components'], 'state')));
        self::assertSame($first['status']['scheduledWorks'], $pair['status']['scheduledWorks'], 'Nothing is read under the freeze');
        self::assertCount(1, $pair['status']['maintenancePeriods'], 'The freeze is observed before the pair is built');
        self::assertNull($pair['status']['maintenancePeriods'][0]['completionObservedAt']);
        self::assertSame(1, $this->hints($frames));
    }

    public function testWorkStartMovesTheStartToNowWithoutMailAndThePublicPageReadsIt(): void
    {
        $this->env->recipient('reader@example.test');
        $payload = $this->payload(60);
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $payload)->error);
        $this->settle(fn (): bool => ($this->pair()['status']['scheduledWorks'][0]['startsAt'] ?? null) === $payload['startsAt']);
        $row = $this->row();
        self::assertNotNull($row['last_mail_queued_at'], 'The announcement itself was mailed');

        $before = TimeHelper::nowMs();
        [$reply] = $this->command(CliCommands::STATUS_TEST_WORK_START, [StatusTestCommandConstants::FIELD_PUBLIC_ID => $row['public_id']]);
        $after = TimeHelper::nowMs();
        self::assertTrue($reply->isOk(), json_encode($reply->payload, JSON_THROW_ON_ERROR));
        $startsAt = $reply->payload[StatusTestCommandConstants::FIELD_STARTS_AT];
        self::assertSame($row['public_id'], $reply->payload[StatusTestCommandConstants::FIELD_PUBLIC_ID]);
        self::assertSame(2, $reply->payload[StatusTestCommandConstants::FIELD_VERSION]);
        self::assertSame(0, $startsAt % 1000);
        self::assertGreaterThanOrEqual(intdiv($before, 1000) * 1000, $startsAt);
        self::assertLessThanOrEqual($after, $startsAt);
        $moved = $this->row();
        self::assertSame((string)$startsAt, (string)$moved['starts_at']);
        self::assertSame((string)$payload['endsAt'], (string)$moved['ends_at']);
        self::assertSame('2', (string)$moved['version']);
        self::assertSame($row['last_mail_queued_at'], $moved['last_mail_queued_at']);
        self::assertCount(0, new ReflectionProperty(StatusAgent::class, 'subscriberNotices')->getValue($this->env->owner));
        $this->env->tick();
        self::assertSame([], array_filter($this->env->drain(), static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::HILOS_MAIL_SEND), 'A test start mails nobody');

        $this->settle(fn (): bool => ($this->pair()['status']['scheduledWorks'][0]['startsAt'] ?? null) === $startsAt);
    }

    public function testWorkStartRefusesInTheWordsOfTheAdministratorsWrite(): void
    {
        $earlier = $this->payload(20, 20);
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $earlier)->error);
        $later = $this->payload(60);
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE, $later)->error);
        $rows = Database::sql('SELECT id, public_id FROM hilos_status_work ORDER BY starts_at')->rows();
        $this->env->drain();

        $this->refused([], 'Name the maintenance announcement by its public id.');
        $this->refused([StatusTestCommandConstants::FIELD_PUBLIC_ID => str_repeat('f', 32)],
            'This maintenance announcement no longer exists. Refresh the list.');
        $this->refused([StatusTestCommandConstants::FIELD_PUBLIC_ID => $rows[1]['public_id']],
            'This maintenance window overlaps another announcement. Choose a different time.');
        self::assertNull($this->env->submit(HilosSignalConstants::HILOS_STATUS_WORK_CANCEL,
            ['workId' => (int)$rows[0]['id'], 'publicId' => $rows[0]['public_id'], 'expectedVersion' => 1])->error);
        $this->env->drain();
        $this->refused([StatusTestCommandConstants::FIELD_PUBLIC_ID => $rows[0]['public_id']],
            'This maintenance announcement was canceled, started or expired. Refresh the list.');
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        try {
            $this->refused([StatusTestCommandConstants::FIELD_PUBLIC_ID => $rows[1]['public_id']],
                'Status changes are unavailable during maintenance or while runtime state is unavailable.');
        } finally {
            $this->env->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        }
        self::assertSame((string)$later['startsAt'], (string)Database::sql(
            'SELECT starts_at FROM hilos_status_work WHERE id = ?', [(int)$rows[1]['id']],
        )->rows()[0]['starts_at']);
        [$reply] = $this->command(CliCommands::STATUS_TEST_WORK_START, [StatusTestCommandConstants::FIELD_PUBLIC_ID => $rows[1]['public_id']]);
        self::assertTrue($reply->isOk(), json_encode($reply->payload, JSON_THROW_ON_ERROR));
        self::assertSame('2', (string)Database::sql(
            'SELECT version FROM hilos_status_work WHERE id = ?', [(int)$rows[1]['id']],
        )->rows()[0]['version'], 'Once the window is free, the same work starts');
    }

    public function testPendingNowRejectsASecondRequestAndRepliesOnceOnStop(): void
    {
        $this->prepareLargeFrozenArchive();
        [$stale] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        self::assertTrue($stale->isOk());
        $this->env->asOwner(fn () => $this->env->owner->onSignalCommand(
            new CommandRequestDTO('deferred', CliCommands::STATUS_TEST_HEARTBEAT_NOW, []), 'cli', CliCommands::STATUS_TEST_HEARTBEAT_NOW,
        ));
        self::assertSame([], $this->commandReplies($this->env->drain()), 'No success before the complete pair receipt');
        [$busy] = $this->command(CliCommands::STATUS_TEST_HEARTBEAT_NOW);
        self::assertFalse($busy->isOk());
        $this->env->asOwner(fn () => $this->env->owner->onStop());
        $replies = $this->commandReplies($this->env->drain());
        self::assertCount(1, $replies);
        self::assertSame('deferred', $replies[0]->correlationId);
        self::assertFalse($replies[0]->isOk());
        $this->env->asOwner(fn () => $this->env->owner->onStop());
        self::assertSame([], $this->commandReplies($this->env->drain()));
    }

    public function testDeferredNowHasABoundedRefusalBeforeTheClientBudget(): void
    {
        $this->prepareLargeFrozenArchive();
        $this->command(CliCommands::STATUS_TEST_HEARTBEAT_STALE);
        $this->env->asOwner(fn () => $this->env->owner->onSignalCommand(
            new CommandRequestDTO('deadline', CliCommands::STATUS_TEST_HEARTBEAT_NOW, []), 'cli', CliCommands::STATUS_TEST_HEARTBEAT_NOW,
        ));
        self::assertSame([], $this->commandReplies($this->env->drain()));
        $this->env->tick(TimeHelper::nowMs() + 4000);
        $replies = $this->commandReplies($this->env->drain());
        self::assertCount(1, $replies);
        self::assertSame('deadline', $replies[0]->correlationId);
        self::assertFalse($replies[0]->isOk());
        $this->env->asOwner(fn () => $this->env->owner->onStop());
        self::assertSame([], $this->commandReplies($this->env->drain()));
    }

    /** Prepares enough real archive rows to require several serialization portions. */
    private function prepareLargeFrozenArchive(): void
    {
        $now = TimeHelper::nowMs();
        $this->env->asOwner(function () use ($now): void {
            for ($index = 0; $index < 128; ++$index) {
                Hilos::$db->statusSilencePeriods->actions->import(str_pad(dechex($index), 32, '0', STR_PAD_LEFT), $now - 400000, $now - 300000);
            }
            $this->env->owner->onStop();
            $this->env->owner->onStart();
        });
        $this->settle(fn (): bool => count($this->pair()['status']['silencePeriods'] ?? []) === 128);
        $this->env->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->settle(fn (): bool => ($this->pair()['status']['components'][0]['state'] ?? null) === 'maintenance');
    }

    /**
     * @param list<SignalDTO> $frames Queued owner frames
     * @return list<CommandReplyDTO> Command replies only
     */
    private function commandReplies(array $frames): array
    {
        return array_values(array_map(static fn (SignalDTO $frame): CommandReplyDTO => $frame->data,
            array_filter($frames, static fn (SignalDTO $frame): bool => $frame->signalType->getType() === SignalTypeConstants::COMMAND_REPLY)));
    }

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @return array{0: CommandReplyDTO, 1: list<SignalDTO>} The owner's one reply, and every other frame queued with it
     */
    private function command(string $command, array $payload = []): array
    {
        self::assertSame([], $this->env->drain());
        $this->env->asOwner(fn () => $this->env->owner->onSignalCommand(new CommandRequestDTO('cid-status', $command, $payload), 'cli', $command));
        self::assertFalse(Database::hasOpenTransactions());
        $frames = $this->env->drain();
        $replies = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalType->getType() === SignalTypeConstants::COMMAND_REPLY));
        for ($tick = 0; $tick < 100 && $replies === []; ++$tick) {
            $this->env->tick();
            $frames = array_merge($frames, $this->env->drain());
            $replies = array_values(array_filter($frames, static fn (object $frame): bool =>
                $frame->signalType->getType() === SignalTypeConstants::COMMAND_REPLY));
        }
        self::assertCount(1, $replies);
        self::assertInstanceOf(CommandReplyDTO::class, $replies[0]->data);
        self::assertSame('cid-status', $replies[0]->data->correlationId);
        return [$replies[0]->data, array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalType->getType() !== SignalTypeConstants::COMMAND_REPLY))];
    }

    /**
     * @param array<string, mixed> $payload Request payload
     * @param string $message Expected refusal, word for word
     */
    private function refused(array $payload, string $message): void
    {
        [$reply, $frames] = $this->command(CliCommands::STATUS_TEST_WORK_START, $payload);
        self::assertFalse($reply->isOk());
        self::assertSame($message, $reply->payload['message']);
        self::assertSame([], $frames);
    }

    /** @param Closure(): bool $settled Reader-visible condition the owner's ordinary ticks bring about */
    private function settle(Closure $settled): void
    {
        for ($tick = 0; $tick < self::SETTLE_TICKS && !$settled(); ++$tick) {
            $this->env->tick();
        }
        $this->env->drain();
        self::assertTrue($settled(), 'The owner did not settle within its bounded ticks');
    }

    /** @return ?array{status: array<string, mixed>, heartbeat: array<string, mixed>} Coherent pair as a reader sees it */
    private function pair(): ?array
    {
        foreach ([StatusPublicFiles::STATUS_FILENAME, StatusPublicFiles::HEARTBEAT_FILENAME] as $name) {
            if (!is_file(Hilos::$fs->status[$name]->getPath())) {
                return null;
            }
        }
        $status = json_decode(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR);
        $heartbeat = json_decode(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR);
        return $status['revision'] === $heartbeat['revision'] ? ['status' => $status, 'heartbeat' => $heartbeat] : null;
    }

    /**
     * @param list<SignalDTO> $frames Queued frames
     * @return int How many of them are the public files hint
     */
    private function hints(array $frames): int
    {
        return count(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === HilosSignalConstants::STATUS_FILES_CHANGED));
    }

    /**
     * @param int $startInSeconds Whole seconds from now to the start
     * @param int $lengthInSeconds Whole seconds of the window
     * @return array<string, mixed> Whole-second future form values
     */
    private function payload(int $startInSeconds, int $lengthInSeconds = 60): array
    {
        $start = (intdiv(TimeHelper::nowMs(), 1000) + $startInSeconds) * 1000;
        return ['title' => 'Upgrade', 'description' => null, 'componentKeys' => ['service'],
            'startsAt' => $start, 'endsAt' => $start + $lengthInSeconds * 1000];
    }

    /** @return array<string, mixed> The one work row, read past the ORM */
    private function row(): array
    {
        return Database::sql('SELECT * FROM hilos_status_work ORDER BY id')->rows()[0];
    }
}
