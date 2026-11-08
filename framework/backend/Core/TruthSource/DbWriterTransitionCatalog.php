<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;

/**
 * Closed, versioned DB writer transitions from the approved Writer Map.
 *
 * Definitions never learn permissions from current claims. A cutover must trim its old states here
 * and in the independent test ceiling in the same commit that removes its active debt key.
 */
class DbWriterTransitionCatalog
{
    protected const string CHANNEL_GUARD = 'HIL-1893';
    protected const string PUSH_GONE = 'HIL-1895';
    protected const string RENAME_LINK = 'HIL-1896';
    protected const string RENAME_UNLINK = 'HIL-1897';
    protected const string DELIVERY_PRUNE = 'HIL-1902';
    protected const string FACTOR_REPLACE = 'HIL-1903';
    protected const string BACKUP_CODES_REPLACE = 'HIL-1904';
    protected const string PUSH_OWN_UPDATE = 'HIL-1905';
    protected const string PUSH_FOREIGN_RECREATE = 'HIL-1906';
    protected const string TRUST_RENEW = 'HIL-1907';
    protected const string TRUST_CAP = 'HIL-1908';
    protected const string TRUST_FORGET = 'HIL-1909';
    protected const string DELIVERY_RETRY = 'HIL-1910';
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
            $replacement = match ($collection) {
                HilosDbContext::secondFactors => self::FACTOR_REPLACE,
                HilosDbContext::secondFactorBackupCodes => self::BACKUP_CODES_REPLACE,
                default => null,
            };
            if ($replacement !== null) {
                $pending[$replacement] = $this->claim($users, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove);
            }
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
            [self::TRUST_RENEW => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
                self::TRUST_CAP => $this->claim($holder, DbWriterClaimWidth::Whole,
                    TruthSourceOperation::Update, TruthSourceOperation::Remove),
                self::TRUST_FORGET => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
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
            [self::PUSH_GONE => $this->claim($notifications, DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
                self::PUSH_OWN_UPDATE => $this->claim($notifications, DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
                self::PUSH_FOREIGN_RECREATE => $this->claim($notifications, DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
                self::ERASURE => $this->claim($holder, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
        );
        $definitions[HilosDbContext::userRenames] = $this->renameStates($hilosClass);
        $definitions[HilosDbContext::notificationDeliveries] = $this->deliveryStates($hilosClass);

        return $definitions;
    }

    /**
     * @param class-string<Hilos> $hilosClass Inspected topology
     * @param ?class-string<AbstractAgent> $chat Optional chat history owner, supplied only by its project provider
     * @return list<DbWriterTransitionState> Journal link, unlink and terminal transitions
     */
    protected function renameStates(string $hilosClass, ?string $chat = null): array
    {
        $person = $this->role($hilosClass, HilosAgentType::HILOS_USER);
        $pending = [self::RENAME_LINK => $this->claim($this->role($hilosClass, HilosAgentType::HILOS_USERS_LIBRARY),
            DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
            self::ERASURE => $this->claim($this->role($hilosClass, HilosAgentType::HILOS_SESSIONS_LIBRARY),
                DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)];
        $completed = [self::RENAME_LINK => $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Update),
            self::ERASURE => $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Remove)];
        if ($chat !== null) {
            $pending[self::RENAME_UNLINK] = $this->claim($chat, DbWriterClaimWidth::Whole, TruthSourceOperation::Update);
            $completed[self::RENAME_UNLINK] = $this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Update);
        }

        return $this->states($this->claim($person, DbWriterClaimWidth::Set, TruthSourceOperation::Add), $pending, $completed);
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
            if ($claims === [] && $leaf !== self::CHANNEL_GUARD) {
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
            $partitions = isset($pending[self::CHANNEL_GUARD]) && !in_array(self::CHANNEL_GUARD, $unpaid, true)
                ? [DbWriterPartition::NotificationChannelShard] : [];
            $states[] = new DbWriterTransitionState($unpaid, $this->mergeClaims($claims), $partitions);
        }

        return $states;
    }

    /**
     * @param class-string<Hilos> $hilosClass Inspected topology
     * @return list<DbWriterTransitionState> Channel guard, retention, retry and erasure transitions
     */
    private function deliveryStates(string $hilosClass): array
    {
        $channels = [];
        foreach ([HilosAgentType::HILOS_MAIL, HilosAgentType::HILOS_SMS, HilosAgentType::HILOS_PUSH] as $agentType) {
            $channels = [...$channels, ...$this->claim($this->role($hilosClass, $agentType),
                DbWriterClaimWidth::Whole, TruthSourceOperation::Update)];
        }
        $library = $this->role($hilosClass, HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY);
        $channelRemoval = array_map(static fn (DbWriterClaim $claim): DbWriterClaim => new DbWriterClaim(
            $claim->ownerClass, $claim->width, TruthSourceOperations::of(TruthSourceOperation::Remove),
        ), $channels);

        return $this->states(
            [...$this->claim($library, DbWriterClaimWidth::Whole, TruthSourceOperation::Add), ...$channels],
            [...($channels === [] ? [] : [self::CHANNEL_GUARD => []]),
                self::DELIVERY_PRUNE => $this->claim($library, DbWriterClaimWidth::Whole, TruthSourceOperation::Remove),
                self::DELIVERY_RETRY => $this->claim($library, DbWriterClaimWidth::Whole, TruthSourceOperation::Update),
                self::ERASURE => $this->claim($this->role($hilosClass, HilosAgentType::HILOS_SESSIONS_LIBRARY),
                    DbWriterClaimWidth::Whole, TruthSourceOperation::Remove)],
            [self::DELIVERY_PRUNE => $channelRemoval, self::ERASURE => $channelRemoval],
            [self::DELIVERY_PRUNE => [self::CHANNEL_GUARD], self::DELIVERY_RETRY => [self::CHANNEL_GUARD]],
        );
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
