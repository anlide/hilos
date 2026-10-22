<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertGreaterThan(0, $connection->connectedAt);
        $this->assertSame('seeded', $connection->label);
        $this->assertFalse($connection->probablyOffline, 'a socket that just opened is not doubted');
        $this->assertSame(1, $connection->baselineMarks);
        $this->assertSame(
            [
                HilosConnection::acceptKey => 'ak-1',
                HilosConnection::userId => 42,
                HilosConnection::connectedAt => $connection->connectedAt,
                HilosConnection::probablyOffline => false,
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
            HilosConnection::connectedAt => 1710000000,
            HilosConnection::probablyOffline => false,
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
            HilosConnection::connectedAt => 1710000000,
            HilosConnection::probablyOffline => false,
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

    public function testTheDoubtFlagTravelsThroughTheRowOnBothStages(): void
    {
        $presence = PresenceConnectionFixture::create('ak-6', 42);
        $presence->probablyOffline = true;
        $session = SessionConnectionFixture::create('ak-7', 5, 'tok-a', 17);
        $session->probablyOffline = true;

        $this->assertTrue($presence->toArray()[HilosConnection::probablyOffline]);
        $this->assertTrue(PresenceConnectionFixture::fromRow($presence->toArray())->probablyOffline);
        $this->assertTrue($session->toArray()[HilosConnection::probablyOffline]);
        $hydrated = SessionConnectionFixture::fromRow($session->toArray());
        $this->assertTrue($hydrated->probablyOffline);
        $this->assertSame('tok-a', $hydrated->sessionToken);
        $this->assertSame('seeded', $hydrated->label);
    }

    /**
     * A full row that does not carry the flag is a broken row on the presence stage: nothing is
     * minted for a key that did not arrive, and the row is refused like one that lost the moment
     * its socket opened.
     */
    public function testAPresenceRowWithoutTheDoubtFlagIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        PresenceConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-8',
            HilosConnection::userId => 42,
            HilosConnection::connectedAt => 1710000000,
            PresenceConnectionFixture::label => 'old',
        ]);
    }

    /**
     * The same refusal one stage up: the session half carried in full does not stand in for the
     * base field the row lost.
     */
    public function testASessionRowWithoutTheDoubtFlagIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-9',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            SessionConnectionFixture::label => 'old',
        ]);
    }

    /**
     * @return array<string, array{mixed}> Values that are present under the flag's key and are not a boolean
     */
    public static function nonBooleanFlags(): array
    {
        return [
            'null' => [null],
            'integer' => [1],
            'string' => ['true'],
        ];
    }

    #[DataProvider('nonBooleanFlags')]
    public function testFromRowRefusesADoubtFlagThatIsNotABoolean(mixed $flag): void
    {
        $this->expectException(InvalidFormatException::class);

        PresenceConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-10',
            HilosConnection::userId => 42,
            HilosConnection::connectedAt => 1710000000,
            HilosConnection::probablyOffline => $flag,
            PresenceConnectionFixture::label => 'guest',
        ]);
    }

    #[DataProvider('nonBooleanFlags')]
    public function testApplyDiffRefusesADoubtFlagThatIsNotABoolean(mixed $flag): void
    {
        $connection = SessionConnectionFixture::create('ak-11', 5, 'tok-a', 17);

        $this->expectException(InvalidFormatException::class);

        $connection->applyDiff([HilosConnection::probablyOffline => $flag]);
    }

    /**
     * A diff carries what changed: the flag moves when the diff carries it, and a diff about any
     * other field - base, session or the project's own - leaves it where it was.
     */
    public function testApplyDiffMovesTheDoubtFlagOnlyWhenItCarriesIt(): void
    {
        $connection = SessionConnectionFixture::create('ak-12', 5, 'tok-a', 17);

        $connection->applyDiff([
            HilosConnection::probablyOffline => true,
            SessionConnectionFixture::label => 'moved',
        ]);
        $this->assertTrue($connection->probablyOffline);
        $this->assertSame('moved', $connection->label);
        $this->assertSame(5, $connection->userId);
        $this->assertSame('tok-a', $connection->sessionToken);

        $connection->applyDiff([
            HilosConnection::userId => 6,
            HilosSessionConnection::sessionToken => 'tok-b',
            SessionConnectionFixture::label => 'again',
        ]);
        $this->assertTrue($connection->probablyOffline, 'a diff that does not carry the flag leaves it raised');
        $this->assertSame(6, $connection->userId);
        $this->assertSame('tok-b', $connection->sessionToken);
        $this->assertSame('again', $connection->label);

        $connection->applyDiff([HilosConnection::probablyOffline => false]);
        $this->assertFalse($connection->probablyOffline);
        $this->assertSame('again', $connection->label);
    }

    public function testPresenceStageCarriesNoSessionToken(): void
    {
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::sessionToken));
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::sessionId));
        $this->assertFalse(method_exists(PresenceConnectionFixtures::class, 'findAllBySessionToken'));
    }

    public function testSessionStageCarriesTheTokenOnTopOfTheBaseFields(): void
    {
        $connection = SessionConnectionFixture::create('ak-4', 5, 'tok-a', 17);

        $this->assertSame('tok-a', $connection->sessionToken);
        $this->assertSame(17, $connection->sessionId);
        $this->assertSame(1, $connection->baselineMarks);
        $this->assertSame(
            [
                HilosConnection::acceptKey => 'ak-4',
                HilosConnection::userId => 5,
                HilosConnection::connectedAt => $connection->connectedAt,
                HilosConnection::probablyOffline => false,
                HilosSessionConnection::sessionToken => 'tok-a',
                HilosSessionConnection::sessionId => 17,
                SessionConnectionFixture::label => 'seeded',
            ],
            $connection->toArray(),
        );

        $hydrated = SessionConnectionFixture::fromRow($connection->toArray());
        $this->assertSame('tok-a', $hydrated->sessionToken);
        $this->assertSame(17, $hydrated->sessionId);

        $hydrated->applyDiff([
            HilosSessionConnection::sessionToken => 'tok-b',
            HilosSessionConnection::sessionId => 19,
        ]);
        $this->assertSame('tok-b', $hydrated->sessionToken);
        $this->assertSame(19, $hydrated->sessionId);
    }

    public function testSessionCreateLeavesTheTokenNullWhenTheSocketBelongsToNoSession(): void
    {
        $connection = SessionConnectionFixture::create('ak-5', null);

        $this->assertNull($connection->sessionToken);
        $this->assertNull($connection->sessionId);
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
