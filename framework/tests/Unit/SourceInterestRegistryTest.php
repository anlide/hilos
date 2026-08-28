<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use PHPUnit\Framework\TestCase;

/**
 * Who in one process reads which collection, and when a read of it is answered (HIL-717).
 *
 * The registry is the reading half of the truth-source map, and the half that decides whether a
 * frame is worth sending at all. Two things about it are load-bearing and neither is obvious
 * from the method names: interest belongs to a consumer rather than to the process, so a
 * collection outlives every consumer but the last one; and saying what you want is not the same
 * as being allowed to read it, so there is a window in which a read is refused although the
 * wiring is correct.
 *
 * A stale entry here is not a leak but a wrong answer twice over: the process would keep asking
 * the master for frames nobody reads, and would answer a read out of a copy that stopped being
 * kept up to date.
 */
final class SourceInterestRegistryTest extends TestCase
{
    /** @var string Collection the cases register interest in */
    private const string COLLECTION = 'unitSourceInterestRows';

    /** @var string Second collection, for the cases about one consumer reading several */
    private const string OTHER_COLLECTION = 'unitSourceInterestOther';

    protected function setUp(): void
    {
        // Readiness is a question only a worker can answer with "not yet", so the cases are put
        // where one stands; elsewhere the registry answers every read and there is no state
        // machine left to test.
        SourceInterestRegistry::readsWhatIsDelivered();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent('unit_source_interest:1'));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('unit-accept-key'));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature('unitSourceInterest'));

        parent::tearDown();
    }

    /**
     * What this case put into the report, out of everything the process reports.
     *
     * The registry is process-wide and the framework declares its own feature rows while mounting
     * them, so the whole report carries collections no case here registered. Filtering to the
     * case's own keys keeps the assertion about what it is for - each collection named once, in
     * the order it was first asked for - instead of about who else ran first.
     *
     * @return list<string> RT collections of this case, in report order
     */
    private function reportedHere(): array
    {
        return array_values(array_filter(
            SourceInterestRegistry::collections(SourceChange::KIND_RT),
            static fn(string $collectionKey): bool => in_array(
                $collectionKey,
                [self::COLLECTION, self::OTHER_COLLECTION],
                true,
            ),
        ));
    }

    public function testAnUnknownCollectionIsNeitherDeclaredNorReady(): void
    {
        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testRegisteringDeclaresTheCollectionWithoutMakingItReadable(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );

        $this->assertTrue(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testStateArrivingMakesTheCollectionReadable(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);

        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testStateArrivingForACollectionNobodyReadsIsIgnored(): void
    {
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);

        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testASecondReaderJoinsAReadyCollectionWithoutWaitingAgain(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );

        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testTheReportedListNamesEachCollectionOnce(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );

        $this->assertSame([self::COLLECTION, self::OTHER_COLLECTION], $this->reportedHere());
    }

    public function testTheTwoKindsAreCountedApart(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_DB,
            self::COLLECTION,
            SourceConsumer::feature('unitSourceInterest'),
        );

        $this->assertTrue(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertSame([], $this->reportedHere());
    }

    public function testACollectionSurvivesEveryConsumerButTheLast(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);

        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('unit-accept-key'));

        $this->assertTrue(SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testTheLastConsumerLeavingTakesTheInterestWithIt(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);

        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent('unit_source_interest:1'));

        $this->assertFalse(SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertSame([self::OTHER_COLLECTION], $this->reportedHere());
    }

    public function testAReturningReaderWaitsForTheStateAgain(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, self::COLLECTION);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('unit-accept-key'));

        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );

        $this->assertTrue(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, self::COLLECTION));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testTheThreeKindsOfConsumerNeverCollide(): void
    {
        $this->assertSame('agent:chat_moderator:1', SourceConsumer::agent('chat_moderator:1'));
        $this->assertSame('page:abc123', SourceConsumer::page('abc123'));
        $this->assertSame('feature:backup', SourceConsumer::feature('backup'));
    }

    /**
     * The addressed half of the presence question (HIL-711): news about a collection has to reach
     * the readers it concerns, and a name is what a caller turns back into the thing it addresses.
     */
    public function testTheReadersOfOneCollectionAreNamed(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );

        $this->assertSame(
            [SourceConsumer::page('unit-accept-key')],
            SourceInterestRegistry::consumersOf(SourceChange::KIND_RT, self::COLLECTION),
        );
    }

    public function testACollectionNobodyReadsNamesNoReaders(): void
    {
        $this->assertSame(
            [],
            SourceInterestRegistry::consumersOf(SourceChange::KIND_RT, self::COLLECTION),
        );
    }

    /**
     * What ONE reader reads, which a per-process list cannot answer: a verdict over everything a
     * page is being shown is assembled from exactly this (HIL-711).
     */
    public function testWhatOneReaderReadsIsAskedOfItAlone(): void
    {
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::OTHER_COLLECTION,
            SourceConsumer::page('unit-accept-key'),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_source_interest:1'),
        );

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::page('unit-accept-key'),
                SourceChange::KIND_RT,
            ),
        );
    }

    public function testAReaderThatAskedForNothingReadsNothing(): void
    {
        $this->assertSame(
            [],
            SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::page('unit-accept-key'),
                SourceChange::KIND_RT,
            ),
        );
    }

    /**
     * The other direction of {@see SourceConsumer::page()}: a caller holding a consumer name must
     * reach the connection behind it without knowing how the name was spelled. An agent and a
     * feature have no connection, and say so rather than answering with part of their own name.
     */
    public function testOnlyAPageConsumerNamesAConnection(): void
    {
        $this->assertSame('abc123', SourceConsumer::acceptKeyOf(SourceConsumer::page('abc123')));
        $this->assertNull(SourceConsumer::acceptKeyOf(SourceConsumer::agent('chat_moderator:1')));
        $this->assertNull(SourceConsumer::acceptKeyOf(SourceConsumer::feature('backup')));
    }
}
