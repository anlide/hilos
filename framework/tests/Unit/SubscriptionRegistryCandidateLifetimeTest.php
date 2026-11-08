<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use ReflectionProperty;
use SplQueue;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** Finite subscription cohorts keep scalar identities without pinning immutable payloads. */
final class SubscriptionRegistryCandidateLifetimeTest extends TestCase
{
    public function testUnadvancedPageCandidatesReleaseDepartedPayloadsWithoutGc(): void
    {
        $registry = new SubscriptionRegistry();
        $references = [];
        for ($index = 0; $index < 260; $index++) {
            $registry->subscribeToPage('browser-' . $index, 'p', ['value' => $index]);
            $references[] = WeakReference::create($registry->pageSubscription('browser-' . $index));
        }
        $candidates = $registry->pageSubscriptionCandidates();
        for ($index = 0; $index < 260; $index++) {
            $registry->unsubscribeFromAll('browser-' . $index);
        }
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        $registry->subscribeToPage('browser-0', 'p', []);
        self::assertSame([], iterator_to_array($candidates));
    }

    public function testUnadvancedGroupCandidatesReleaseDepartedPayloadsWithoutGc(): void
    {
        $registry = new SubscriptionRegistry();
        $references = [];
        for ($index = 0; $index < 260; $index++) {
            $key = 'browser-' . $index;
            $registry->subscribeToGroup($key, 'g', ['value' => $index]);
            $references[] = $this->groupReference($registry, $key, 'g');
        }
        $candidates = $registry->groupSubscriptionCandidates('g');
        for ($index = 0; $index < 260; $index++) {
            $registry->unsubscribeFromAllGroups('browser-' . $index);
        }
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        $registry->subscribeToGroup('browser-0', 'g', []);
        self::assertSame([], iterator_to_array($candidates));
    }

    public function testUnadvancedNameCandidatesReleaseAllOriginalMembershipPayloads(): void
    {
        $registry = new SubscriptionRegistry();
        $references = [];
        for ($index = 0; $index < 260; $index++) {
            $group = 'g:' . $index;
            $registry->subscribeToGroup('browser', $group, ['value' => $index]);
            $references[] = $this->groupReference($registry, 'browser', $group);
        }
        $candidates = $registry->groupNameCandidates('browser', 'g');
        $registry->unsubscribeFromAllGroups('browser');
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        $registry->subscribeToGroup('browser', 'g', []);
        foreach ($candidates as $_) {
        }
        self::assertNull($candidates->getReturn());
    }

    public function testGroupOrderFollowsConnectionsAndExcludesLaterSameKeyMemberships(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToGroup('first', 'other', []);
        $registry->subscribeToGroup('second', 'g', []);
        $registry->subscribeToGroup('first', 'g', []);
        self::assertSame(['first', 'second'], array_keys(iterator_to_array($registry->groupSubscriptionCandidates('g'))));
        $candidates = $registry->groupSubscriptionCandidates('g');
        $registry->unsubscribeFromGroup('first', 'g');
        $registry->subscribeToGroup('first', 'g', []);
        $registry->subscribeToGroup('late', 'g', []);
        self::assertSame(['first', 'second'], array_keys($original = iterator_to_array($candidates)));
        self::assertNull($original['first']);
        self::assertNotNull($original['second']);
        $registry->unsubscribeFromAllGroups('first');
        $registry->subscribeToGroup('first', 'g', []);
        self::assertSame(['second', 'late', 'first'], array_keys(iterator_to_array($registry->groupSubscriptionCandidates('g'))));
    }

    public function testPageBindingAndParamsReplaceOnlyTheirOriginalIncarnations(): void
    {
        $registry = new SubscriptionRegistry();
        foreach (['first', 'bound', 'updated', 'last'] as $key) {
            $registry->subscribeToPage($key, 'p', []);
        }
        $candidates = $registry->pageSubscriptionCandidates();
        $registry->bindPageAgent('bound', 'p', 'owner', null);
        $registry->updatePageSubscription('updated', 'p', ['value' => 1]);
        $registry->subscribeToPage('late', 'p', []);
        $original = iterator_to_array($candidates);
        self::assertSame(['first', 'bound', 'updated', 'last'], array_keys($original));
        self::assertNotNull($original['first']);
        self::assertNull($original['bound']);
        self::assertNull($original['updated']);
        self::assertNotNull($original['last']);
    }

    public function testNamesExcludeAnUpdatedExactMembershipAndKeepOriginalPrefixOrder(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToGroup('browser', 'g', []);
        $registry->subscribeToGroup('browser', 'g:first', []);
        $registry->subscribeToGroup('browser', 'g:last', []);
        $candidates = $registry->groupNameCandidates('browser', 'g');
        $registry->updateGroupSubscription('browser', 'g', ['value' => 1]);
        $registry->unsubscribeFromGroup('browser', 'g:first');
        $registry->subscribeToGroup('browser', 'g:first', []);
        foreach ($candidates as $_) {
        }
        self::assertSame('g:last', $candidates->getReturn());
        self::assertSame('g', $registry->groupSubscriptionName('browser', 'g'));
    }

    public function testAdvancedCohortsReleaseUnvisitedPayloadsBeforeTheirNextStep(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('first', 'p', []);
        $registry->subscribeToGroup('first', 'g', []);
        $references = [];
        for ($index = 0; $index < 260; $index++) {
            $key = 'browser-' . $index;
            $registry->subscribeToPage($key, 'p', []);
            $registry->subscribeToGroup($key, 'g', []);
            $references[] = WeakReference::create($registry->pageSubscription($key));
            $references[] = $this->groupReference($registry, $key, 'g');
        }
        $pages = $registry->pageSubscriptionCandidates();
        $groups = $registry->groupSubscriptionCandidates('g');
        self::assertNotNull($pages->current());
        self::assertNotNull($groups->current());
        for ($index = 0; $index < 260; $index++) {
            $registry->unsubscribeFromAll('browser-' . $index);
        }
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        $registry->subscribeToPage('browser-0', 'p', []);
        $registry->subscribeToGroup('browser-0', 'g', []);
        $pages->next();
        $groups->next();
        self::assertFalse($pages->valid());
        self::assertFalse($groups->valid());
    }

    public function testRetiredNameIndexReleasesOneNodePerStepBesideASameKeyReplacement(): void
    {
        $registry = new SubscriptionRegistry();
        $queue = new SplQueue();
        $registry->setCleanup(new DeliveryCleanup(static function (Generator $cleanup) use ($queue): void {
            $queue->enqueue($cleanup);
        }));
        for ($index = 0; $index < 260; $index++) {
            $registry->subscribeToGroup('browser', 'g:' . $index, []);
        }
        $oldIndex = new ReflectionProperty(SubscriptionRegistry::class, 'groupNameIndexes')->getValue($registry)['browser'];
        $names = $registry->groupNameCandidates('browser', 'g');
        self::assertTrue($names->valid());
        $registry->unsubscribeFromAllGroups('browser');
        self::assertCount(260, iterator_to_array($oldIndex->candidates()));
        $registry->subscribeToGroup('browser', 'g', []);
        while (!$queue->isEmpty()) {
            $cleanup = $queue->dequeue();
            $previous = count(iterator_to_array($oldIndex->candidates()));
            while ($cleanup->valid()) {
                $cleanup->next();
                $remaining = count(iterator_to_array($oldIndex->candidates()));
                self::assertGreaterThanOrEqual($previous - 1, $remaining);
                self::assertLessThanOrEqual($previous, $remaining);
                $previous = $remaining;
            }
        }
        self::assertTrue($oldIndex->isEmpty());
        foreach ($names as $_) {
        }
        self::assertNull($names->getReturn());
        self::assertSame('g', $registry->groupSubscriptionName('browser', 'g'));
    }

    public function testEmptyCapturedIndexesCannotAdmitFutureSubscriptions(): void
    {
        $registry = new SubscriptionRegistry();
        $pages = $registry->pageSubscriptionCandidates();
        $groups = $registry->groupSubscriptionCandidates('g');
        $names = $registry->groupNameCandidates('browser', 'g');
        $registry->subscribeToPage('browser', 'p', []);
        $registry->subscribeToGroup('browser', 'g', []);
        self::assertSame([], iterator_to_array($pages));
        self::assertSame([], iterator_to_array($groups));
        foreach ($names as $_) {
        }
        self::assertNull($names->getReturn());
    }

    /**
     * @param SubscriptionRegistry $registry Membership owner
     * @param string $acceptKey Original connection
     * @param string $group Full membership name
     * @return WeakReference Membership reference without retaining a candidate cursor
     */
    private function groupReference(SubscriptionRegistry $registry, string $acceptKey, string $group): WeakReference
    {
        foreach ($registry->groupSubscriptionCandidates($group) as $key => $subscription) {
            if ($key === $acceptKey) {
                return WeakReference::create($subscription);
            }
        }
        self::fail('The fixture membership must be present');
    }
}
