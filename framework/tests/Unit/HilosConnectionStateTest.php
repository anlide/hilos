<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Utils\Helpers\TimeHelper;
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
        $before = TimeHelper::nowMs();
        $connection = PresenceConnectionFixture::create('ak-1', 42);
        $after = TimeHelper::nowMs();

        $this->assertGreaterThanOrEqual($before, $connection->connectedAt);
        $this->assertLessThanOrEqual($after, $connection->connectedAt);
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
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            PresenceConnectionFixture::label => 'guest',
        ]);

        $this->assertSame('ak-2', $connection->getId());
        $this->assertNull($connection->userId);
        $this->assertSame('guest', $connection->label);
        $this->assertSame(1710000000123, $connection->connectedAt);
        $this->assertSame(1710000000123, $connection->toArray()[HilosConnection::connectedAt]);
        $this->assertSame(1, $connection->baselineMarks);
    }

    public function testFromRowRefusesARowMissingTheAcceptKey(): void
    {
        $this->expectException(InvalidFormatException::class);

        PresenceConnectionFixture::fromRow([
            HilosConnection::userId => 42,
            HilosConnection::connectedAt => 1710000000123,
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
            HilosConnection::connectedAt => 1710000000123,
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
            HilosConnection::connectedAt => 1710000000123,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            HilosSessionConnection::languageId => 3,
            HilosSessionConnection::localeId => 11,
            HilosSessionConnection::browserLanguages => 'ru-UA,ru,en',
            HilosSessionConnection::connectionCountry => 'UA',
            HilosSessionConnection::addressLanguage => 'de',
            SessionConnectionFixture::label => 'old',
        ]);
    }

    /**
     * The language, the region, the browser's request, the connection's country and the address
     * language are nullable session-stage fields: a full row that does not carry them reads them as
     * null, as it reads the session token and id, and nothing is minted in their place.
     */
    public function testASessionRowWithoutTheTabLanguageReadsItAsNull(): void
    {
        $hydrated = SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-13',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            SessionConnectionFixture::label => 'old',
        ]);

        $this->assertNull($hydrated->languageId);
        $this->assertNull($hydrated->localeId);
        $this->assertNull($hydrated->browserLanguages);
        $this->assertNull($hydrated->connectionCountry);
        $this->assertNull($hydrated->addressLanguage);
        $this->assertSame('tok-a', $hydrated->sessionToken);
    }

    /** @return array<string, array{string, mixed}> Language and region fields holding a value that is not an id */
    public static function nonIntegerTabLanguages(): array
    {
        return [
            'language as text' => [HilosSessionConnection::languageId, '3'],
            'language as a flag' => [HilosSessionConnection::languageId, true],
            'region as text' => [HilosSessionConnection::localeId, '11'],
            'region as a fraction' => [HilosSessionConnection::localeId, 1.5],
        ];
    }

    /**
     * @param string $field Language or region field
     * @param mixed $value Value that is not an integer id
     */
    #[DataProvider('nonIntegerTabLanguages')]
    public function testFromRowRefusesATabLanguageThatIsNotAnId(string $field, mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-14',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            HilosSessionConnection::languageId => 3,
            HilosSessionConnection::localeId => 11,
            HilosSessionConnection::browserLanguages => 'ru-UA,ru,en',
            HilosSessionConnection::connectionCountry => 'UA',
            HilosSessionConnection::addressLanguage => 'de',
            SessionConnectionFixture::label => 'old',
            $field => $value,
        ]);
    }

    /** @return array<string, array{mixed}> Browser requests that are not a stored list of tags */
    public static function nonTextBrowserLanguages(): array
    {
        return [
            'a number' => [3],
            'a flag' => [true],
            'a list' => [['ru-UA', 'ru']],
        ];
    }

    /**
     * @param mixed $value Value that is not the text of a stored list
     */
    #[DataProvider('nonTextBrowserLanguages')]
    public function testFromRowRefusesABrowserRequestThatIsNotText(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-16',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            HilosSessionConnection::languageId => 3,
            HilosSessionConnection::localeId => 11,
            HilosSessionConnection::browserLanguages => $value,
            HilosSessionConnection::connectionCountry => 'UA',
            HilosSessionConnection::addressLanguage => 'de',
            SessionConnectionFixture::label => 'old',
        ]);
    }

    /** @return array<string, array{mixed}> Connection countries that are not the text of a code */
    public static function nonTextConnectionCountries(): array
    {
        return [
            'a number' => [380],
            'a flag' => [true],
            'a list' => [['UA']],
        ];
    }

    /**
     * @param mixed $value Value that is not the text of a country code
     */
    #[DataProvider('nonTextConnectionCountries')]
    public function testFromRowRefusesAConnectionCountryThatIsNotText(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-17',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            HilosSessionConnection::languageId => 3,
            HilosSessionConnection::localeId => 11,
            HilosSessionConnection::browserLanguages => 'ru-UA,ru,en',
            HilosSessionConnection::connectionCountry => $value,
            HilosSessionConnection::addressLanguage => 'de',
            SessionConnectionFixture::label => 'old',
        ]);
    }

    /** @return array<string, array{mixed}> Address languages that are not the text of a code */
    public static function nonTextAddressLanguages(): array
    {
        return [
            'a number' => [49],
            'a flag' => [true],
            'a list' => [['de']],
        ];
    }

    /**
     * @param mixed $value Value that is not the text of a language code
     */
    #[DataProvider('nonTextAddressLanguages')]
    public function testFromRowRefusesAnAddressLanguageThatIsNotText(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionConnectionFixture::fromRow([
            HilosConnection::acceptKey => 'ak-18',
            HilosConnection::userId => 5,
            HilosConnection::connectedAt => 1710000000123,
            HilosConnection::probablyOffline => false,
            HilosSessionConnection::sessionToken => 'tok-a',
            HilosSessionConnection::sessionId => 17,
            HilosSessionConnection::languageId => 3,
            HilosSessionConnection::localeId => 11,
            HilosSessionConnection::browserLanguages => 'ru-UA,ru,en',
            HilosSessionConnection::connectionCountry => 'UA',
            HilosSessionConnection::addressLanguage => $value,
            SessionConnectionFixture::label => 'old',
        ]);
    }

    /**
     * A diff carries what changed: the language and the region move when it carries them, back to
     * null too, and a diff about any other field leaves them where they were.
     */
    public function testApplyDiffMovesTheTabLanguageOnlyWhenItCarriesIt(): void
    {
        $connection = SessionConnectionFixture::create('ak-15', 5, 'tok-a', 17);
        $this->assertNull($connection->languageId);
        $this->assertNull($connection->localeId);

        $connection->applyDiff([HilosSessionConnection::languageId => 3, HilosSessionConnection::localeId => 11]);
        $this->assertSame(3, $connection->languageId);
        $this->assertSame(11, $connection->localeId);

        $connection->applyDiff([HilosSessionConnection::sessionToken => 'tok-b', SessionConnectionFixture::label => 'moved']);
        $this->assertSame(3, $connection->languageId);
        $this->assertSame(11, $connection->localeId);

        $connection->applyDiff([HilosSessionConnection::localeId => null]);
        $this->assertSame(3, $connection->languageId);
        $this->assertNull($connection->localeId);

        $connection->applyDiff([HilosSessionConnection::browserLanguages => 'de,en']);
        $this->assertSame('de,en', $connection->browserLanguages);
        $this->assertSame(3, $connection->languageId);
        $connection->applyDiff([HilosSessionConnection::languageId => 4]);
        $this->assertSame('de,en', $connection->browserLanguages);

        $connection->applyDiff([HilosSessionConnection::connectionCountry => 'JP']);
        $this->assertSame('JP', $connection->connectionCountry);
        $this->assertSame('de,en', $connection->browserLanguages);
        $connection->applyDiff([HilosSessionConnection::browserLanguages => 'ja']);
        $this->assertSame('JP', $connection->connectionCountry);
        $connection->applyDiff([HilosSessionConnection::connectionCountry => null]);
        $this->assertNull($connection->connectionCountry);
        $this->assertSame('ja', $connection->browserLanguages);

        $connection->applyDiff([HilosSessionConnection::addressLanguage => 'de']);
        $this->assertSame('de', $connection->addressLanguage);
        $this->assertSame('ja', $connection->browserLanguages);
        $connection->applyDiff([HilosSessionConnection::connectionCountry => 'DE']);
        $this->assertSame('de', $connection->addressLanguage);
        $connection->applyDiff([HilosSessionConnection::addressLanguage => null]);
        $this->assertNull($connection->addressLanguage);
        $this->assertSame('DE', $connection->connectionCountry);
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
            HilosConnection::connectedAt => 1710000000123,
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
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::languageId));
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::localeId));
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::browserLanguages));
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::connectionCountry));
        $this->assertFalse(property_exists(PresenceConnectionFixture::class, HilosSessionConnection::addressLanguage));
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
                HilosSessionConnection::languageId => null,
                HilosSessionConnection::localeId => null,
                HilosSessionConnection::browserLanguages => null,
                HilosSessionConnection::connectionCountry => null,
                HilosSessionConnection::addressLanguage => null,
                SessionConnectionFixture::label => 'seeded',
            ],
            $connection->toArray(),
        );

        $hydrated = SessionConnectionFixture::fromRow($connection->toArray());
        $this->assertSame('tok-a', $hydrated->sessionToken);
        $this->assertSame(17, $hydrated->sessionId);
        $this->assertSame($connection->connectedAt, $hydrated->connectedAt);

        $hydrated->applyDiff([
            HilosSessionConnection::sessionToken => 'tok-b',
            HilosSessionConnection::sessionId => 19,
            HilosConnection::connectedAt => 1710000000456,
        ]);
        $this->assertSame(1710000000456, $hydrated->connectedAt);
        $this->assertSame(1710000000456, SessionConnectionFixture::fromRow($hydrated->toArray())->connectedAt);
        $hydrated->applyDiff([SessionConnectionFixture::label => 'unchanged moment']);
        $this->assertSame(1710000000456, $hydrated->connectedAt);
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

    protected const array OWN_FIELD_TYPES = [self::label => 'text'];
    private const string DEFAULT_LABEL = '';

    /** Subclass-owned field, to prove composition with the base halves. */
    public string $label {
        get => isset($this->rowValues()[self::label]) ? $this->rowValues()[self::label]->toNative() : self::DEFAULT_LABEL;
        set {
            $this->setRowValue(self::label, RtRowValues::fromNative($value));
        }
    }

    /** How many times the base marked the sync baseline on this row. */
    public int $baselineMarks = 0;

    public function markRtSyncBaseline(): void
    {
        $this->baselineMarks++;
        parent::markRtSyncBaseline();
    }

    /** @return array<string, mixed> Base defaults followed by the fixture's own label */
    protected function rowFieldDefaults(): array
    {
        return parent::rowFieldDefaults() + [self::label => self::DEFAULT_LABEL];
    }

    /**
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
        $this->label = 'seeded';
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @throws InvalidFormatException When the row lost the subclass-owned field
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield from $this->readFields($row, self::OWN_FIELD_TYPES);
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield from $this->exportFields($out, [self::label => '']);
    }

    /**
     * @param RtRowReader $diff Changed fields => values
     * @throws InvalidFormatException When the diff carries the subclass-owned field as a non-string
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield from $this->readFields($diff, self::OWN_FIELD_TYPES, true);
    }
}

/**
 * Minimal session-stage connection: the same own field, one stage up.
 */
final class SessionConnectionFixture extends HilosSessionConnection
{
    public const string label = 'label';

    protected const array OWN_FIELD_TYPES = [self::label => 'text'];
    private const string DEFAULT_LABEL = '';

    /** Subclass-owned field, to prove the chain reaches it through both stages. */
    public string $label {
        get => isset($this->rowValues()[self::label]) ? $this->rowValues()[self::label]->toNative() : self::DEFAULT_LABEL;
        set {
            $this->setRowValue(self::label, RtRowValues::fromNative($value));
        }
    }

    /** How many times the base marked the sync baseline on this row. */
    public int $baselineMarks = 0;

    public function markRtSyncBaseline(): void
    {
        $this->baselineMarks++;
        parent::markRtSyncBaseline();
    }

    /** @return array<string, mixed> Base defaults followed by the fixture's own label */
    protected function rowFieldDefaults(): array
    {
        return parent::rowFieldDefaults() + [self::label => self::DEFAULT_LABEL];
    }

    /**
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
        $this->label = 'seeded';
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @throws InvalidFormatException When the row lost the subclass-owned field
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield from $this->readFields($row, self::OWN_FIELD_TYPES);
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield from $this->exportFields($out, [self::label => '']);
    }

    /**
     * @param RtRowReader $diff Changed fields => values
     * @throws InvalidFormatException When the diff carries the subclass-owned field as a non-string
     * @return Generator<int, RtWork, int, void> Project field operation
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield from $this->readFields($diff, self::OWN_FIELD_TYPES, true);
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
