<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the announcement a row update makes on the source bus (HIL-761).
 *
 * Membership changes have been announced since HIL-603, and an update was left out for a good
 * reason: nobody in the process cared that a row's columns had changed. The log write level does
 * - it lives in a settings row and has to follow an administrator's edit without a restart - so
 * the update joins its neighbours here.
 *
 * Two things are pinned. The fact is announced once, carrying the diff and the collection key,
 * on both roads a row can change by: this process writing it, and this process applying somebody
 * else's write, which has to be marked as applied so nothing rebroadcasts it. And the two
 * subscribers that existed before this change stay uninterested in an update - the view cache
 * because the wrapper still points at the state the diff went into, the outgoing RT sync because
 * it is not a database subscriber at all.
 */
final class ObjectUpdateAnnouncementTest extends TestCase
{
    private const string AGENT = 'unit-object-update-announcement-host';

    protected function setUp(): void
    {
        parent::setUp();

        SourceChangeBus::reset();
        TruthSourceRegistry::register(UpdateAnnouncementObject::COLLECTION_KEY, true, self::AGENT);
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregister(UpdateAnnouncementObject::COLLECTION_KEY, self::AGENT);
        SourceChangeBus::reset();

        parent::tearDown();
    }

    public function testALocalUpdateIsAnnouncedOnceWithTheDiffAndTheCollectionKey(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new UpdateAnnouncementRecorder($seen));

        $object = UpdateAnnouncementObject::persisted(7, 'before');
        $object->rename('after');
        $object->sync();

        $this->assertCount(1, $seen);
        $this->assertSame(UpdateAnnouncementObject::COLLECTION_KEY, $seen[0][0]->sourceKey);
        $this->assertSame('7', $seen[0][0]->sourceId);
        $this->assertSame(TableMutationType::Update, $seen[0][0]->mutationType);
        $this->assertSame(['value' => 'after'], $seen[0][0]->row);
        $this->assertSame(SourceChangeProvenance::LocalWrite, $seen[0][1]);
    }

    /**
     * A create is not announced here: putting the object into its collection is the membership
     * change, and the collection announces that one itself.
     */
    public function testACreateIsNotAnnouncedBySync(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new UpdateAnnouncementRecorder($seen));

        UpdateAnnouncementObject::fresh('first')->sync();

        $this->assertSame([], $seen);
    }

    public function testAnUpdateWithNothingChangedAnnouncesNothing(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new UpdateAnnouncementRecorder($seen));

        UpdateAnnouncementObject::persisted(7, 'before')->sync();

        $this->assertSame([], $seen);
    }

    /**
     * The provenance is what stops an applied change from being sent straight back out: without
     * it whoever rebroadcasts local writes would echo the neighbour's edit to the neighbour.
     */
    public function testAnAppliedRemoteUpdateIsAnnouncedAsApplied(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new UpdateAnnouncementRecorder($seen));

        UpdateAnnouncementObject::persisted(7, 'before')->applyDbSyncEntityUpdate(['value' => 'after']);

        $this->assertCount(1, $seen);
        $this->assertSame(TableMutationType::Update, $seen[0][0]->mutationType);
        $this->assertSame(['value' => 'after'], $seen[0][0]->row);
        $this->assertSame(SourceChangeProvenance::AppliedRemote, $seen[0][1]);
    }

    /**
     * The provenance is restored afterwards rather than assumed to be a local write, so a write
     * running around the applied one is not handed the applied marking.
     */
    public function testTheAppliedMarkingDoesNotLeakPastTheAnnouncement(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new UpdateAnnouncementRecorder($seen));

        UpdateAnnouncementObject::persisted(7, 'before')->applyDbSyncEntityUpdate(['value' => 'after']);

        $object = UpdateAnnouncementObject::persisted(8, 'before');
        $object->rename('after');
        $object->sync();

        $this->assertCount(2, $seen);
        $this->assertSame(SourceChangeProvenance::LocalWrite, $seen[1][1]);
    }

    /**
     * The two subscribers the framework registered before this change do nothing with an update,
     * so adding the announcement costs them nothing and cannot have changed their behavior.
     */
    public function testTheExistingSubscribersDoNotReactToAnUpdate(): void
    {
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());

        $object = UpdateAnnouncementObject::persisted(7, 'before');
        $object->rename('after');
        $object->sync();

        // Nothing to assert but the absence of trouble: with no DB context and no signal router
        // mounted, a subscriber that took an interest in this fact would raise on the way.
        $this->assertSame('after', $object->currentValue());
    }
}

/**
 * Minimal entity fixture whose one column can change, and which never reaches storage.
 *
 * The write is skipped rather than staged: these cases are about what the object announces
 * after a successful write, and a real one would need a database to succeed in.
 */
final class UpdateAnnouncementEntity extends Entity
{
    public const string _table = 'object_update_announcement_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'value'];
    public const array _types = ['id' => 'integer', 'value' => 'string'];

    public ?int $id = null;

    public ?string $value = null;

    /**
     * Reports the row as stored without storing it.
     *
     * @param Entity $originalEntity State the diff is taken against
     * @return bool Always true, the write having been skipped
     */
    public function saveDiff(Entity $originalEntity): bool
    {
        return true;
    }

    /**
     * Reports the row as stored without storing it, minting an id as a real insert would.
     *
     * @param list<string> $columns Columns a real insert would write; unused, nothing is written
     * @return bool Always true, the write having been skipped
     */
    public function save(array $columns = []): bool
    {
        $this->id ??= 1;
        $this->flushRelated();

        return true;
    }

    /**
     * Whether the row exists in the table; here, whether it was given an id.
     *
     * @return bool True when the row counts as persisted
     */
    public function isRelated(): bool
    {
        return $this->id !== null;
    }
}

/**
 * Minimal object fixture wrapping the announcement entity.
 */
final class UpdateAnnouncementObject extends Object_
{
    public const string ENTITY_CLASS = UpdateAnnouncementEntity::class;

    public const string COLLECTION_KEY = 'object_update_announcement_test';

    /**
     * Builds an object standing for a row that already exists.
     *
     * @param int $id Row id
     * @param string $value Stored value
     * @return self Object holding that row
     */
    public static function persisted(int $id, string $value): self
    {
        $entity = new UpdateAnnouncementEntity();
        $entity->id = $id;
        $entity->value = $value;

        return self::fromEntity($entity);
    }

    /**
     * Builds an object standing for a row that does not exist yet.
     *
     * @param string $value Value to store
     * @return self Object holding that row
     */
    public static function fresh(string $value): self
    {
        $object = self::create();
        $object->rename($value);

        return $object;
    }

    /**
     * Changes the one column this fixture carries.
     *
     * @param string $value New value
     */
    public function rename(string $value): void
    {
        $this->entity->value = $value;
    }

    /**
     * Reads back the one column this fixture carries.
     *
     * @return ?string Current value
     */
    public function currentValue(): ?string
    {
        return $this->entity->value;
    }

    /**
     * @return string Collection key the announcement is made under
     */
    protected static function getCollectionKey(): string
    {
        return self::COLLECTION_KEY;
    }
}

/**
 * Subscriber that records every fact it is handed, with the provenance it arrived under.
 */
final class UpdateAnnouncementRecorder implements SourceChangeSubscriberInterface
{
    /**
     * @param list<array{SourceChange, SourceChangeProvenance}> $seen Facts recorded so far, by reference
     */
    public function __construct(private array &$seen)
    {
    }

    /**
     * Records one announced fact.
     *
     * @param SourceChange $change Fact describing what happened to the source
     * @param SourceChangeProvenance $provenance Whether this process authored the write
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = [$change, $provenance];
    }
}
