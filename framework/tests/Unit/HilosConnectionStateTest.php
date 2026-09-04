<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the inheritable framework connection runtime base (HIL-361, HIL-509).
 *
 * Two things are under test and they are different questions. The composition
 * template: a project cannot compose the base half wrongly, because the base runs
 * it — always first, always including the sync baseline. And the two stages: what
 * a project gets is decided by which stage it stands on, so a presence-stage row
 * has no session token to forget and no token lookup to call.
 */
final class HilosConnectionStateTest extends TestCase
{
    public function testCreateRunsBaseThenOwnAndMarksTheBaseline(): void
    {
        $connection = PresenceConnectionFixture::create('ak-1', 42);

        $this->assertSame('ak-1', $connection->getId());
        $this->assertSame('ak-1', $connection->acceptKey);
        $this->assertSame(42, $connection->userId);
        $this->assertSame('seeded', $connection->label);
        $this->assertSame(1, $connection->baselineMarks);
        $this->assertSame(
            [
                HilosConnection::acceptKey => 'ak-1',
                HilosConnection::userId => 42,
                PresenceConnectionFixture::label => 'seeded',
            ],
            $connection->toArray(),
        );
    }

    public function testFromRowRunsBaseThenOwnAndMarksTheBaseline(): void
    {
        $connection = PresenceConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-2',
            HilosConnection::userId => null,
            PresenceConnectionFixture::label => 'guest',
        ]);

        $this->assertSame('ak-2', $connection->getId());
        $this->assertNull($connection->userId);
        $this->assertSame('guest', $connection->label);
        $this->assertSame(1, $connection->baselineMarks);
    }

    public function testFromRowRefusesARowMissingTheAcceptKey(): void
    {
        $this->expectException(InvalidFormatException::class);

        PresenceConnectionFixture::fromRow([
            HilosConnection::userId => 42,
            PresenceConnectionFixture::label => 'guest',
        ]);
    }

    public function testApplyDiffMovesTheUserAndTheOwnFieldButNotTheAcceptKey(): void
    {
        $connection = PresenceConnectionFixture::create('ak-3', null);

        $connection->applyDiff([
            HilosConnection::acceptKey => 'ignored',
            HilosConnection::userId => 7,
            PresenceConnectionFixture::label => 'moved',
        ]);

        $this->assertSame('ak-3', $connection->acceptKey);
        $this->assertSame(7, $connection->userId);
        $this->assertSame('moved', $connection->label);

        $connection->applyDiff([HilosConnection::userId => null]);
        $this->assertNull($connection->userId);
    }

    public function testPresenceStageCarriesNoSessionToken(): void
    {
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::sessionToken));
        $this->assertFalse(method_exists(PresenceConnectionFixtures::class, 'findAllBySessionToken'));
    }

    public function testSessionStageCarriesTheTokenOnTopOfTheBaseFields(): void
    {
        $connection = SessionConnectionFixture::create('ak-4', 5, 'tok-a');

        $this->assertSame('tok-a', $connection->sessionToken);
        $this->assertSame(1, $connection->baselineMarks);
        $this->assertSame(
            [
                HilosConnection::acceptKey => 'ak-4',
                HilosConnection::userId => 5,
                HilosSessionConnection::sessionToken => 'tok-a',
                SessionConnectionFixture::label => 'seeded',
            ],
            $connection->toArray(),
        );

        $hydrated = SessionConnectionFixture::fromRow($connection->toArray());
        $this->assertSame('tok-a', $hydrated->sessionToken);

        $hydrated->applyDiff([HilosSessionConnection::sessionToken => 'tok-b']);
        $this->assertSame('tok-b', $hydrated->sessionToken);
    }

    public function testSessionCreateLeavesTheTokenNullWhenTheSocketBelongsToNoSession(): void
    {
        $connection = SessionConnectionFixture::create('ak-5', null);

        $this->assertNull($connection->sessionToken);
    }

    public function testPresenceCollectionLooksUpByUser(): void
    {
        $connections = PresenceConnectionFixtures::init();
        $connections->add(PresenceConnectionFixture::create('ak-a', 1));
        $connections->add(PresenceConnectionFixture::create('ak-b', 1));
        $connections->add(PresenceConnectionFixture::create('ak-c', null));

        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findByUser(1)));
        $this->assertSame([], $connections->findByUser(2));
        $this->assertSame([], $connections->findByUser(null));
        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findAuthenticated()));
    }

    public function testSessionCollectionLooksUpByToken(): void
    {
        $connections = SessionConnectionFixtures::init();
        $connections->add(SessionConnectionFixture::create('ak-a', 1, 'tok-x'));
        $connections->add(SessionConnectionFixture::create('ak-b', 1, 'tok-x'));
        $connections->add(SessionConnectionFixture::create('ak-c', 2, 'tok-y'));

        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findAllBySessionToken('tok-x')));
        $this->assertSame(['ak-c'], array_keys($connections->findAllBySessionToken('tok-y')));
        $this->assertSame([], $connections->findAllBySessionToken(''));
        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findByUser(1)));
    }
}

/**
 * Minimal presence-stage connection: the base pair plus one field of its own.
 *
 * The baseline counter is what proves the base marks the sync baseline itself: a
 * project row that had to mark it would be free to forget, which is the failure
 * this template exists to make impossible.
 */
final class PresenceConnectionFixture extends HilosConnection
{
    public const string label = 'label';

    /** Subclass-owned field, to prove composition with the base halves. */
    public string $label = '';

    /** How many times the base marked the sync baseline on this row. */
    public int $baselineMarks = 0;

    public function markRtSyncBaseline(): void
    {
        $this->baselineMarks++;
        parent::markRtSyncBaseline();
    }

    protected function initOwn(): void
    {
        $this->label = 'seeded';
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @throws InvalidFormatException When the row lost the subclass-owned field
     */
    protected function hydrateOwn(array $row): void
    {
        $this->label = self::requireString($row, self::label);
    }

    /**
     * @return array<string, mixed> Subclass-owned half of the row
     */
    protected function ownToArray(): array
    {
        return [self::label => $this->label];
    }

    /**
     * @param array<string, mixed> $diff Changed fields => values
     * @throws InvalidFormatException When the diff carries the subclass-owned field as a non-string
     */
    protected function applyOwnDiff(array $diff): void
    {
        $this->label = self::patchString($diff, self::label, $this->label);
    }
}

/**
 * Minimal session-stage connection: the same own field, one stage up.
 */
final class SessionConnectionFixture extends HilosSessionConnection
{
    public const string label = 'label';

    /** Subclass-owned field, to prove the chain reaches it through both stages. */
    public string $label = '';

    /** How many times the base marked the sync baseline on this row. */
    public int $baselineMarks = 0;

    public function markRtSyncBaseline(): void
    {
        $this->baselineMarks++;
        parent::markRtSyncBaseline();
    }

    protected function initOwn(): void
    {
        $this->label = 'seeded';
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @throws InvalidFormatException When the row lost the subclass-owned field
     */
    protected function hydrateOwn(array $row): void
    {
        $this->label = self::requireString($row, self::label);
    }

    /**
     * @return array<string, mixed> Subclass-owned half of the row
     */
    protected function ownToArray(): array
    {
        return [self::label => $this->label];
    }

    /**
     * @param array<string, mixed> $diff Changed fields => values
     * @throws InvalidFormatException When the diff carries the subclass-owned field as a non-string
     */
    protected function applyOwnDiff(array $diff): void
    {
        $this->label = self::patchString($diff, self::label, $this->label);
    }
}

/**
 * Concrete presence-stage collection over the presence fixture.
 *
 * @extends HilosConnections<PresenceConnectionFixture>
 */
final class PresenceConnectionFixtures extends HilosConnections
{
    public const string STATE_CLASS = PresenceConnectionFixture::class;
}

/**
 * Concrete session-stage collection over the session fixture.
 *
 * @extends HilosSessionConnections<SessionConnectionFixture>
 */
final class SessionConnectionFixtures extends HilosSessionConnections
{
    public const string STATE_CLASS = SessionConnectionFixture::class;
}
