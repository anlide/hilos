<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;

/**
 * Closed, versioned DB writer transitions from the approved Writer Map.
 *
 * Definitions never learn permissions from current claims. A cutover must trim its old states here
 * and in the independent test ceiling in the same commit that removes its active debt key.
 */
class DbWriterTransitionCatalog
{
    protected const string TRUST_CAP = 'HIL-1908';
    protected const string ERASURE = 'HIL-1952';
    protected const string MERGE = 'HIL-1999';

    /**
     * @param class-string<Hilos> $hilosClass Topology being inspected, independent of the active facade
     * @return array<string, list<DbWriterTransitionState>> Applicable closed state graphs
     */
    public function all(string $hilosClass): array
    {
        $definitions = $this->buildDefinitions($hilosClass);
        foreach ($definitions as $collection => $states) {
            $changingClasses = [];
            foreach ($states[0]->claims as $claim) {
                if ($claim->operations->allows(TruthSourceOperation::Update)
                    || $claim->operations->allows(TruthSourceOperation::Remove)) {
                    $changingClasses[$claim->ownerClass] = true;
                }
            }
            if (count($changingClasses) < 2) {
                unset($definitions[$collection]);
            }
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    /**
     * Fixed operation reasons, not a snapshot of the declarations being judged.
     * Registration determines roles even when a feature or an installation's sending switch is off.
     *
     * @param class-string<Hilos> $hilosClass Topology whose worker roles are used
     * @return array<string, list<DbWriterTransitionState>> Definitions with their starting state first
     */
    protected function buildDefinitions(string $hilosClass): array
    {
        $users = $this->role($hilosClass, HilosAgentType::HILOS_USERS_LIBRARY);
        $person = $this->role($hilosClass, HilosAgentType::HILOS_USER);
        $holder = $this->role($hilosClass, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $notifications = $this->role($hilosClass, HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY);
        $oauth = $this->role($hilosClass, HilosAgentType::HILOS_OAUTH);
        $definitions = [];

        $definitions[HilosDbContext::users] = $this->states(
            [...$this->claim($users, DbWriterClaimWidth::Whole, TruthSourceOperation::Add),
                ...$this->claim($person, DbWriterClaimWidth::Rows, TruthSourceOperation::Update)],
            [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
                self::MERGE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Update)],
            [self::ERASURE => $this->claim($person, DbWriterClaimWidth::Rows, TruthSourceOperation::Remove)],
        );

        foreach ([HilosDbContext::identities, HilosDbContext::passkeyCredentials,
            HilosDbContext::secondFactors, HilosDbContext::secondFactorBackupCodes,
            HilosDbContext::secondFactorResets] as $collection) {
            $base = [...$this->claim($users, DbWriterClaimWidth::Whole, TruthSourceOperation::Add),
                ...$this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Update, TruthSourceOperation::Remove)];
            if ($collection === HilosDbContext::identities) {
                $base = [...$base, ...$this->claim($oauth, DbWriterClaimWidth::Whole, TruthSourceOperation::Add)];
            }
            $mergeOperations = match ($collection) {
                HilosDbContext::passkeyCredentials => [TruthSourceOperation::Update],
                HilosDbContext::secondFactorResets => [TruthSourceOperation::Remove],
                default => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
            };
            $pending = [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
                self::MERGE => $this->claim($holder, DbWriterClaimWidth::Whole, ...$mergeOperations)];
            $definitions[$collection] = $this->states($base, $pending);
        }

        $definitions[HilosDbContext::secondFactorSettings] = $this->states(
            $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Add, TruthSourceOperation::Update),
            [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
                self::MERGE => $this->claim($holder, DbWriterClaimWidth::Whole, ...TruthSourceOperation::ALL)],
            [self::ERASURE => $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Remove)],
        );

        $definitions[HilosDbContext::secondFactorTrusts] = $this->states(
            [...$this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Add),
                ...$this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Update, TruthSourceOperation::Remove)],
            [self::TRUST_CAP => $this->claim($holder, DbWriterClaimWidth::Whole,
                    TruthSourceOperation::Update, TruthSourceOperation::Remove),
                self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
                self::MERGE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );

        $definitions[HilosDbContext::legalAcceptances] = $this->states(
            base: [...$this->claim($users, DbWriterClaimWidth::Whole, TruthSourceOperation::Add),
                ...$this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Remove)],
            pending: [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );

        foreach ([HilosDbContext::stepUps, HilosDbContext::userPhotos, HilosDbContext::notificationPreferences,
            HilosDbContext::accountDeletions, HilosDbContext::notifications] as $collection) {
            $operations = match ($collection) {
                HilosDbContext::accountDeletions, HilosDbContext::notifications => [
                    TruthSourceOperation::Update, TruthSourceOperation::Remove],
                default => TruthSourceOperation::ALL,
            };
            $creator = match ($collection) {
                HilosDbContext::accountDeletions => $users,
                HilosDbContext::notifications => $notifications,
                default => null,
            };
            $definitions[$collection] = $this->states(
                [...$this->claim($person, DbWriterClaimWidth::Set, ...$operations),
                    ...$this->claim($creator, DbWriterClaimWidth::Whole, TruthSourceOperation::Add)],
                [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole,
                    $collection === HilosDbContext::accountDeletions ? TruthSourceOperation::Update : TruthSourceOperation::Remove)],
            );
        }

        $definitions[HilosDbContext::verifications] = $this->states(
            $this->claim($users, DbWriterClaimWidth::Whole, ...TruthSourceOperation::ALL),
            [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );
        foreach ([HilosDbContext::dataExports => HilosAgentType::HILOS_DATA_EXPORT,
            HilosDbContext::legalAcceptanceExports => HilosAgentType::HILOS_LEGAL] as $collection => $agentType) {
            $definitions[$collection] = $this->states(
                $this->claim($this->role($hilosClass, $agentType), DbWriterClaimWidth::Whole, ...TruthSourceOperation::ALL),
                [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
            );
        }

        $definitions[HilosDbContext::pushSubscriptions] = $this->states(
            [...$this->claim($notifications, DbWriterClaimWidth::Whole, TruthSourceOperation::Add),
                ...$this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Update, TruthSourceOperation::Remove)],
            [self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );
        $definitions[HilosDbContext::userRenames] = $this->renameStates($hilosClass);
        $definitions[HilosDbContext::notificationDeliveries] = $this->deliveryStates($hilosClass);

        return $definitions;
    }

    /**
     * @param class-string<Hilos> $hilosClass Inspected topology
     * @return list<DbWriterTransitionState> Journal erasure transitions
     */
    protected function renameStates(string $hilosClass): array
    {
        $person = $this->role($hilosClass, HilosAgentType::HILOS_USER);
        $pending = [self::ERASURE => $this->claim($this->role($hilosClass, HilosAgentType::HILOS_SESSIONS_LIBRARY),
                DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)];
        $completed = [self::ERASURE => $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Remove)];

        return $this->states(
            $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Add, TruthSourceOperation::Update),
            $pending,
            $completed,
        );
    }

    /**
     * @param class-string<Hilos> $hilosClass Inspected topology
     * @param string $agentType Fixed framework role
     * @return ?class-string<AbstractAgent> Registered worker, absent when the project does not carry that role
     */
    protected function role(string $hilosClass, string $agentType): ?string
    {
        return AgentRegistry::workerClass($hilosClass::AGENTS[$agentType] ?? null);
    }

    /**
     * @param ?class-string<AbstractAgent> $ownerClass Optional registered role
     * @param DbWriterClaimWidth $width Fixed declared width
     * @param TruthSourceOperation ...$operations Fixed allowed operations
     * @return list<DbWriterClaim> Claim fragment, empty for an absent role
     */
    protected function claim(?string $ownerClass, DbWriterClaimWidth $width, TruthSourceOperation ...$operations): array
    {
        return $ownerClass === null ? [] : [new DbWriterClaim($ownerClass, $width, TruthSourceOperations::of(...$operations))];
    }

    /**
     * Enumerates the finite graph. Independent leaves commute; terminal moves follow all ordinary moves,
     * and merge follows erasure. Contributions sharing a permission are unioned until their last reason leaves.
     *
     * @param list<DbWriterClaim> $base Claims that survive every move
     * @param array<string, list<DbWriterClaim>> $pending Claims contributed by each unpaid leaf
     * @param array<string, list<DbWriterClaim>> $completed Claims acquired at each live cutover
     * @param array<string, list<string>> $requires Additional completed prerequisites per leaf
     * @return list<DbWriterTransitionState> Exact permitted vectors, starting with every leaf unpaid
     */
    protected function states(array $base, array $pending, array $completed = [], array $requires = []): array
    {
        foreach ($pending as $leaf => $claims) {
            if ($claims === []) {
                unset($pending[$leaf], $completed[$leaf]);
            }
        }
        $leaves = array_keys($pending);
        sort($leaves, SORT_STRING);
        $ordinary = array_values(array_diff($leaves, [self::ERASURE, self::MERGE]));
        if (isset($pending[self::ERASURE])) {
            $requires[self::ERASURE] = [...($requires[self::ERASURE] ?? []), ...$ordinary];
        }
        if (isset($pending[self::MERGE])) {
            $requires[self::MERGE] = [...($requires[self::MERGE] ?? []), self::ERASURE, ...$ordinary];
        }
        $subsets = [[]];
        foreach ($leaves as $leaf) {
            foreach ($subsets as $subset) {
                $subsets[] = [...$subset, $leaf];
            }
        }
        $states = [];
        foreach (array_reverse($subsets) as $unpaid) {
            if (!$this->prerequisitesHold($leaves, $unpaid, $requires)) {
                continue;
            }
            $claims = $base;
            foreach ($leaves as $leaf) {
                $claims = [...$claims, ...(in_array($leaf, $unpaid, true) ? $pending[$leaf] : ($completed[$leaf] ?? []))];
            }
            $states[] = new DbWriterTransitionState($unpaid, $this->mergeClaims($claims));
        }

        return $states;
    }

    /**
     * @param class-string<Hilos> $hilosClass Inspected topology
     * @return list<DbWriterTransitionState> Guarded delivery erasure transitions
     */
    private function deliveryStates(string $hilosClass): array
    {
        $channels = [];
        foreach ([HilosAgentType::HILOS_MAIL, HilosAgentType::HILOS_SMS, HilosAgentType::HILOS_PUSH] as $agentType) {
            $worker = $this->role($hilosClass, $agentType);
            if ($worker !== null && is_subclass_of($worker, AbstractDeliveryChannelAgent::class)) {
                $channels = [...$channels, ...$this->claim($worker, DbWriterClaimWidth::Whole,
                    TruthSourceOperation::Update, TruthSourceOperation::Remove)];
            }
        }
        $library = $this->role($hilosClass, HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY);

        $states = $this->states(
            [...$this->claim($library, DbWriterClaimWidth::Whole, TruthSourceOperation::Add), ...$channels],
            [self::ERASURE => $this->claim($this->role($hilosClass, HilosAgentType::HILOS_SESSIONS_LIBRARY),
                DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );

        return array_map(static fn (DbWriterTransitionState $state): DbWriterTransitionState => new DbWriterTransitionState(
            $state->pendingLeaves, $state->claims, $channels === [] ? [] : [DbWriterPartition::NotificationChannelShard],
        ), $states);
    }

    /**
     * @param list<string> $leaves All vertices of this collection's graph
     * @param list<string> $unpaid Outstanding vertices
     * @param array<string, list<string>> $requires Prerequisites of completed vertices
     * @return bool Whether every completed vertex has its prerequisites completed
     */
    private function prerequisitesHold(array $leaves, array $unpaid, array $requires): bool
    {
        foreach ($leaves as $leaf) {
            if (!in_array($leaf, $unpaid, true) && array_intersect($requires[$leaf] ?? [], $unpaid) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<DbWriterClaim> $claims Fixed contributions of live reasons
     * @return list<DbWriterClaim> One union per class and width, in stable order
     */
    private function mergeClaims(array $claims): array
    {
        $merged = [];
        foreach ($claims as $claim) {
            $key = $claim->ownerClass . ':' . $claim->width->value;
            $merged[$key] = isset($merged[$key]) ? new DbWriterClaim(
                $claim->ownerClass, $claim->width, $merged[$key]->operations->merge($claim->operations),
            ) : $claim;
        }
        ksort($merged, SORT_STRING);

        return array_values($merged);
    }
}
