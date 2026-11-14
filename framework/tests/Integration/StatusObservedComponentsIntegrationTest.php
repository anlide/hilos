<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Tests\Fixtures\StatusWorksEnvironment;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real committed observation starts written through the production Status owner's claim. */
final class StatusObservedComponentsIntegrationTest extends FrameworkIntegrationTestCase
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

    public function testRecordIsIdempotentAndNeverRewritesTheFirstMoment(): void
    {
        $this->forgetTheFixtureOwnersRows();
        $this->environment->asOwner(function (): void {
            $first = Hilos::$db->statusObservedComponents->actions->record('daemon', -5000);
            $before = Database::sql('SELECT * FROM `hilos_status_observed_component`')->rows();
            self::assertCount(1, $before);
            self::assertSame('daemon', $before[0]['component_key']);
            self::assertSame(-5000, (int)$before[0]['observed_since']);
            self::assertSame(['id' => $first->id, 'componentKey' => 'daemon', 'observedSince' => -5000], $first->toArray());
            foreach ([-5000, -5001, 7000] as $later) {
                $again = Hilos::$db->statusObservedComponents->actions->record('daemon', $later);
                self::assertSame($first->id, $again->id);
                self::assertSame(-5000, $again->observedSince);
                self::assertSame($before, Database::sql('SELECT * FROM `hilos_status_observed_component`')->rows());
            }
        });
        Hilos::$db->forgetLoadedDataForTest();
        self::assertSame(-5000, Hilos::$db->statusObservedComponents->findByComponentKey('daemon')?->observedSince);
        self::assertNull(Hilos::$db->statusObservedComponents->findByComponentKey('mail'));
    }

    public function testALostInsertRaceReturnsTheCommittedWinnerUnchanged(): void
    {
        $this->forgetTheFixtureOwnersRows();
        $this->environment->asOwner(function (): void {
            // The mirror holds the complete, still empty set; the row then appears behind it in SQL alone, as a lost race
            // leaves it. Only a fresh SQL reread finds that winner: the mirror would answer that the key has no row.
            Hilos::$db->statusObservedComponents->getObjectCollection()->loadAllFromDB();
            Database::sql('INSERT INTO `hilos_status_observed_component` (`component_key`, `observed_since`) VALUES (?, ?)',
                ['daemon', -3000]);
            $winner = Database::sql('SELECT * FROM `hilos_status_observed_component`')->rows();
            $recorded = Hilos::$db->statusObservedComponents->actions->record('daemon', 9000);
            self::assertSame((int)$winner[0]['id'], $recorded->id);
            self::assertSame(-3000, $recorded->observedSince);
            self::assertSame($winner, Database::sql('SELECT * FROM `hilos_status_observed_component`')->rows());
            self::assertSame($recorded->id, Hilos::$db->statusObservedComponents->findByComponentKey('daemon')?->id);
        });
    }

    public function testTheOwnerRecordsItsStartOnlyForAComponentWithoutARow(): void
    {
        $this->settle();
        self::assertSame(['mail', 'service'], array_keys($this->rows()), 'The fixture owner recorded the catalog at its start');
        // One component was recorded long ago; the other never was.
        Database::sql('UPDATE `hilos_status_observed_component` SET `observed_since` = ? WHERE `component_key` = ?', [-7000, 'service']);
        Database::sql('DELETE FROM `hilos_status_observed_component` WHERE `component_key` = ?', ['mail']);
        Hilos::$db->forgetLoadedDataForTest();
        $before = TimeHelper::nowMs();
        $this->restart();
        $after = TimeHelper::nowMs();
        $this->settle();
        $rows = $this->rows();
        self::assertSame(-7000, $rows['service'], 'An existing row wins whatever the owner remembers');
        self::assertGreaterThanOrEqual($before, $rows['mail']);
        self::assertLessThanOrEqual($after, $rows['mail']);
        self::assertSame($rows, $this->published());
    }

    public function testAStartUnderFreezeIsPublishedAndTheSameMomentIsRecordedAtTheLift(): void
    {
        $this->freshInstallation();
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $before = TimeHelper::nowMs();
        $this->restart();
        $after = TimeHelper::nowMs();
        $this->settle();
        $published = $this->published();
        self::assertSame(['mail', 'service'], array_keys($published));
        foreach ($published as $moment) {
            self::assertGreaterThanOrEqual($before, $moment);
            self::assertLessThanOrEqual($after, $moment);
        }
        self::assertSame([], $this->rows(), 'The freeze keeps the database untouched');
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle();
        self::assertSame($published, $this->rows());
        self::assertSame($published, $this->published());
    }

    public function testARestartUnderTheSameFreezeMovesTheStartByNoMoreThanTheFreeze(): void
    {
        $this->freshInstallation();
        $frozenAt = TimeHelper::nowMs();
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->restart();
        $this->settle();
        $first = $this->published();
        $before = TimeHelper::nowMs();
        $this->restart();
        $after = TimeHelper::nowMs();
        $this->settle();
        self::assertSame($first, $this->published(), 'Under the freeze the restarted owner publishes the recovered start');
        self::assertSame([], $this->rows());
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $liftedAt = TimeHelper::nowMs();
        $this->settle();
        $rows = $this->rows();
        self::assertSame(['mail', 'service'], array_keys($rows));
        foreach ($rows as $componentKey => $moment) {
            // The public copy never fills the database: the row gets the restarted owner's memorable moment.
            self::assertGreaterThanOrEqual($before, $moment);
            self::assertLessThanOrEqual($after, $moment);
            self::assertLessThanOrEqual($liftedAt - $frozenAt, $moment - $first[$componentKey]);
        }
        self::assertSame($rows, $this->published());
    }

    public function testAReplacedDatabaseDecidesTheMomentsAndAMissingOneIsTheFreezeEntry(): void
    {
        $this->settle();
        $original = $this->rows();
        self::assertSame($original, $this->published());
        $before = TimeHelper::nowMs();
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->environment->tick();
        $entered = TimeHelper::nowMs();
        $this->settle();
        // The restored database knew the service from long ago and never saw mail.
        Database::sql('UPDATE `hilos_status_observed_component` SET `observed_since` = ? WHERE `component_key` = ?', [-7000, 'service']);
        Database::sql('DELETE FROM `hilos_status_observed_component` WHERE `component_key` = ?', ['mail']);
        Hilos::$db->forgetLoadedDataForTest();
        $this->settle();
        self::assertSame($original, $this->published(), 'Under the freeze the owner publishes what it knew');
        $frozenRevision = $this->snapshot()->revision;
        $this->environment->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle();
        $rows = $this->rows();
        self::assertSame(-7000, $rows['service']);
        self::assertGreaterThanOrEqual($before, $rows['mail']);
        self::assertLessThanOrEqual($entered, $rows['mail']);
        self::assertSame($rows, $this->published());
        self::assertNotSame($frozenRevision, $this->snapshot()->revision);
    }

    /** @return iterable<string, array{string}> Keys the Status component declaration refuses */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Daemon'];
        yield 'leading digit' => ['9daemon'];
        yield 'space' => ['daemon key'];
        yield 'trailing newline' => ["daemon\n"];
        yield 'longer than 128' => [str_repeat('a', 129)];
    }

    /** @param string $componentKey Attempted component key */
    #[DataProvider('invalidKeys')]
    public function testAKeyOutsideTheComponentPatternLeavesNoRow(string $componentKey): void
    {
        $this->forgetTheFixtureOwnersRows();
        $this->environment->asOwner(function () use ($componentKey): void {
            try {
                Hilos::$db->statusObservedComponents->actions->record($componentKey, 0);
                self::fail('A malformed component key must be refused before persistence');
            } catch (ValidationException) {
                self::assertSame([], Database::sql('SELECT * FROM `hilos_status_observed_component`')->rows());
            }
            self::assertSame(str_repeat('a', 128),
                Hilos::$db->statusObservedComponents->actions->record(str_repeat('a', 128), 0)->componentKey);
        });
    }

    /** The fixture owner recorded its catalog at start; these record cases begin from an empty table. */
    private function forgetTheFixtureOwnersRows(): void
    {
        Database::sql('DELETE FROM `hilos_status_observed_component`');
        Hilos::$db->forgetLoadedDataForTest();
    }

    /** No row and no public pair to recover a moment from. */
    private function freshInstallation(): void
    {
        $this->forgetTheFixtureOwnersRows();
        FsPath::delete(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->getPath());
        FsPath::delete(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->getPath());
    }

    private function restart(): void
    {
        $this->environment->asOwner(fn () => $this->environment->owner->onStop());
        $this->environment->drain();
        $this->environment->asOwner(fn () => $this->environment->owner->onStart());
    }

    private function settle(): void
    {
        for ($tick = 0; $tick < 100; ++$tick) {
            $this->environment->tick();
        }
        $this->environment->drain();
    }

    private function snapshot(): StatusPublicSnapshot
    {
        return StatusPublicSnapshot::fromJson(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
    }

    /** @return array<string, int> Committed start of observation by component key, in key order */
    private function rows(): array
    {
        $moments = [];
        foreach (Database::sql('SELECT `component_key`, `observed_since` FROM `hilos_status_observed_component`')->rows() as $row) {
            $moments[$row['component_key']] = (int)$row['observed_since'];
        }
        ksort($moments);
        return $moments;
    }

    /** @return array<string, int> Published start of observation by component key, in key order */
    private function published(): array
    {
        $moments = [];
        foreach ($this->snapshot()->components as $component) {
            $moments[$component->componentKey] = $component->observedSince;
        }
        ksort($moments);
        return $moments;
    }
}
