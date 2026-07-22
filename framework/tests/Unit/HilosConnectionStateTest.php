<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the inheritable framework connection runtime base (HIL-361).
 *
 * Exercises the session-triple contract of {@see HilosConnection} and the
 * session-scoped lookups of {@see HilosConnections} through a minimal concrete
 * subclass that adds one field of its own, proving the base hydrate/diff helpers
 * compose with subclass state.
 */
final class HilosConnectionStateTest extends TestCase
{
    public function testCreateSeedsSessionTripleAndSubclassField(): void
    {
        $connection = HilosConnectionTestFixture::create('ak-1', 42, 'tok-a', 'hello');

        $this->assertSame('ak-1', $connection->getId());
        $this->assertSame('ak-1', $connection->acceptKey);
        $this->assertSame('tok-a', $connection->sessionToken);
        $this->assertSame(42, $connection->userId);
        $this->assertSame('hello', $connection->label);
        $this->assertSame(
            [
                HilosConnection::acceptKey => 'ak-1',
                HilosConnection::sessionToken => 'tok-a',
                HilosConnection::userId => 42,
                HilosConnectionTestFixture::label => 'hello',
            ],
            $connection->toArray(),
        );
    }

    public function testFromRowRoundTripsSessionTriple(): void
    {
        $anonymous = HilosConnectionTestFixture::fromRow([
            HilosConnection::acceptKey => 'ak-2',
            HilosConnection::sessionToken => 'tok-b',
            HilosConnection::userId => null,
            HilosConnectionTestFixture::label => 'guest',
        ]);

        $this->assertSame('ak-2', $anonymous->getId());
        $this->assertSame('tok-b', $anonymous->sessionToken);
        $this->assertNull($anonymous->userId);
        $this->assertSame('guest', $anonymous->label);
    }

    public function testApplyBaseDiffMovesTokenAndUserButNotAcceptKey(): void
    {
        $connection = HilosConnectionTestFixture::create('ak-3', null, 'tok-c', 'x');

        $connection->applyDiff([
            HilosConnection::acceptKey => 'ignored',
            HilosConnection::sessionToken => 'tok-c2',
            HilosConnection::userId => 7,
            HilosConnectionTestFixture::label => 'y',
        ]);

        $this->assertSame('ak-3', $connection->acceptKey);
        $this->assertSame('tok-c2', $connection->sessionToken);
        $this->assertSame(7, $connection->userId);
        $this->assertSame('y', $connection->label);

        $connection->applyDiff([HilosConnection::userId => null]);
        $this->assertNull($connection->userId);
    }

    public function testCollectionLooksUpBySessionTokenAndUser(): void
    {
        $connections = HilosConnectionTestFixtures::init();
        $connections->add(HilosConnectionTestFixture::create('ak-a', 1, 'tok-x', 'a'));
        $connections->add(HilosConnectionTestFixture::create('ak-b', 1, 'tok-x', 'b'));
        $connections->add(HilosConnectionTestFixture::create('ak-c', 2, 'tok-y', 'c'));

        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findAllBySessionToken('tok-x')));
        $this->assertSame(['ak-c'], array_keys($connections->findAllBySessionToken('tok-y')));
        $this->assertSame([], $connections->findAllBySessionToken(''));

        $this->assertSame(['ak-a', 'ak-b'], array_keys($connections->findByUser(1)));
        $this->assertSame(['ak-c'], array_keys($connections->findByUser(2)));
        $this->assertSame([], $connections->findByUser(null));
    }
}

/**
 * Minimal concrete connection: the session triple from the base plus one field.
 */
final class HilosConnectionTestFixture extends HilosConnection
{
    public const string label = 'label';

    /** Subclass-owned field to prove composition with the base helpers. */
    public string $label = '';

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Authenticated user id, or null for anonymous
     * @param string $sessionToken Session cookie token
     * @param string $label Subclass field
     * @return static Seeded connection
     */
    public static function create(string $acceptKey, ?int $userId, string $sessionToken, string $label): static
    {
        $instance = new static();
        $instance->initBase($acceptKey, $userId, $sessionToken);
        $instance->label = $label;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated connection
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->hydrateBase($row);
        $instance->label = (string)($row[self::label] ?? '');
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff Changed fields => values
     */
    public function applyDiff(array $diff): void
    {
        $this->applyBaseDiff($diff);
        if (array_key_exists(self::label, $diff)) {
            $this->label = (string)$diff[self::label];
        }
    }

    /**
     * @return array<string, mixed> Full row (base triple + subclass field)
     */
    public function toArray(): array
    {
        return $this->baseToArray() + [self::label => $this->label];
    }
}

/**
 * Concrete collection over the test fixture connection.
 *
 * @extends HilosConnections<HilosConnectionTestFixture>
 */
final class HilosConnectionTestFixtures extends HilosConnections
{
    public const string STATE_CLASS = HilosConnectionTestFixture::class;
}
