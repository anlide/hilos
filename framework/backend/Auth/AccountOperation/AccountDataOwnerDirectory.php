<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * The one place a project says whose its collections are, which of them its person's agent holds,
 * and which of them the account erasure leaves alone.
 *
 * A project points {@see Hilos::ACCOUNT_DATA_OWNER_DIRECTORY} at a subclass and overrides
 * {@see owners()}, {@see personal()} and {@see exempt()}; the framework's own collections are not
 * declared here. Both account operations read it: {@see AccountErasureParticipantCatalog::installation()}
 * takes the owners after the framework's participants, and {@see AccountMergeProjectCensus::of()}
 * asks them about the loser of a merge, each in the order of this directory.
 *
 * The collections of the person's agent are no owner: that agent's type is the framework's
 * ({@see HilosAgentType::HILOS_USER}), which no project owner may take. They are a part of the
 * person's own share of the erasure, which the agent deletes after the framework's sets and in the
 * same transaction; the merge census asks only the owners.
 *
 * The owners are a list, not a map, so a key typed twice reaches the check instead of silently
 * replacing the first entry. A key is lower snake case with no colon, because the colon separates
 * the parts of an assignment key. One agent type owns for one entry at most, and a framework
 * owner's type owns for none: the type alone names the owner's part of an operation. The whole
 * declaration is checked before any of its three answers is given, and every mistake is named at once.
 */
abstract class AccountDataOwnerDirectory
{
    /** Grammar of an owner key: lower snake case, so no colon of an assignment key. */
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Agent types of the framework's own participants, which no project owner may take. */
    private const array FRAMEWORK_OWNER_TYPES = [
        HilosAgentType::HILOS_USER,
        HilosAgentType::HILOS_USERS_LIBRARY,
        HilosAgentType::HILOS_SESSIONS_LIBRARY,
        HilosAgentType::HILOS_DATA_EXPORT,
        HilosAgentType::HILOS_LEGAL,
    ];

    /**
     * @return list<AccountDataOwner> Declared owners in directory order
     * @throws InvalidTopologyException When the owners, the exemptions or the collections of the person's agent are malformed
     */
    final public static function declaredOwners(): array
    {
        $owners = static::owners();
        self::refuseMalformed($owners, static::exempt(), static::personal());

        return $owners;
    }

    /**
     * @return array<string, AccountErasureExemption> Project collections the erasure leaves alone, by collection key
     * @throws InvalidTopologyException When the owners, the exemptions or the collections of the person's agent are malformed
     */
    final public static function declaredExemptions(): array
    {
        $exempt = static::exempt();
        self::refuseMalformed(static::owners(), $exempt, static::personal());

        return $exempt;
    }

    /**
     * @return list<string> Project collections the person's agent holds, in directory order
     * @throws InvalidTopologyException When the owners, the exemptions or the collections of the person's agent are malformed
     */
    final public static function declaredPersonal(): array
    {
        $personal = static::personal();
        self::refuseMalformed(static::owners(), static::exempt(), $personal);

        return $personal;
    }

    /**
     * A project overrides this to name the owners of its collections:
     *
     * ```php
     * protected static function owners(): array
     * {
     *     return [new AccountDataOwner('chat', ['chatMessages', 'chatEvents'], 'chat', false, self::hasChatRows(...))];
     * }
     * ```
     *
     * @return list<AccountDataOwner> Owners in directory order; none by default
     */
    protected static function owners(): array
    {
        return [];
    }

    /** @return array<string, AccountErasureExemption> Project collection keys the erasure leaves alone, and why; none by default */
    protected static function exempt(): array
    {
        return [];
    }

    /**
     * A project overrides this to name the collections its person's agent holds - the subclass of
     * {@see AbstractUserAgent} claims them in `OWNS_DB_SET` and deletes them in
     * {@see AbstractUserAgent::eraseProjectChildren()}:
     *
     * ```php
     * protected static function personal(): array
     * {
     *     return ['userProductCarts'];
     * }
     * ```
     *
     * Naming a collection here and not deleting it there is the project's mistake, and nothing
     * checks it at startup: the person's row is the last the erasure deletes, and a row that still
     * restricts it stops the erasure's final.
     *
     * @return list<string> Collection keys the person's agent holds; none by default
     */
    protected static function personal(): array
    {
        return [];
    }

    /**
     * @param array<mixed> $owners Owners as declared
     * @param array<mixed> $exempt Exemptions as declared
     * @param array<mixed> $personal Collections of the person's agent as declared
     * @throws InvalidTopologyException When the declaration has a mistake, naming every one
     */
    private static function refuseMalformed(array $owners, array $exempt, array $personal): void
    {
        $errors = [...self::ownerErrors($owners), ...self::exemptionErrors($exempt), ...self::personalErrors($personal)];
        if ($errors !== []) {
            throw InvalidTopologyException::forErrors(Hilos::appClass(), $errors);
        }
    }

    /**
     * @param array<mixed> $owners Owners as declared
     * @return list<string> What is wrong with them
     */
    private static function ownerErrors(array $owners): array
    {
        $errors = [];
        if (!array_is_list($owners)) {
            $errors[] = static::class . ' must declare its account data owners as a list';
        }
        $keys = [];
        $keyByType = [];
        foreach ($owners as $position => $owner) {
            if (!$owner instanceof AccountDataOwner) {
                $errors[] = static::class . " declares an account data owner at position {$position} that is " . get_debug_type($owner);
                continue;
            }
            if (preg_match(self::KEY_PATTERN, $owner->key) !== 1) {
                $errors[] = "Account data owner key [{$owner->key}] does not match " . self::KEY_PATTERN;
            }
            if (isset($keys[$owner->key])) {
                $errors[] = "Account data owner [{$owner->key}] is declared twice";
            }
            $keys[$owner->key] = true;
            if (in_array($owner->ownerType, self::FRAMEWORK_OWNER_TYPES, true)) {
                $errors[] = "Account data owner [{$owner->key}] names the framework owner type [{$owner->ownerType}]";
            } elseif (isset($keyByType[$owner->ownerType])) {
                $errors[] = "Agent type [{$owner->ownerType}] owns for both [{$keyByType[$owner->ownerType]}] and [{$owner->key}]";
            }
            $keyByType[$owner->ownerType] ??= $owner->key;
            foreach (array_keys(Hilos::notificationChannelRegistryClass()::all()) as $name) {
                if (DeliveryOwnerResolver::forChannel($name) === $owner->ownerType) {
                    $errors[] = "Account data owner [{$owner->key}] names the delivery channel owner type [{$owner->ownerType}]";
                }
            }
            if ($owner->collections === []) {
                $errors[] = "Account data owner [{$owner->key}] covers no collection";
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $exempt Exemptions as declared
     * @return list<string> What is wrong with them
     */
    private static function exemptionErrors(array $exempt): array
    {
        $errors = [];
        foreach ($exempt as $collection => $reason) {
            if (!is_string($collection)) {
                $errors[] = static::class . " exempts a collection under the key {$collection}, not a collection key";
            }
            if (!$reason instanceof AccountErasureExemption) {
                $errors[] = static::class . " exempts [{$collection}] for " . get_debug_type($reason)
                    . ', not an ' . AccountErasureExemption::class;
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $personal Collections of the person's agent as declared
     * @return list<string> What is wrong with them
     */
    private static function personalErrors(array $personal): array
    {
        $errors = [];
        if (!array_is_list($personal)) {
            $errors[] = static::class . ' must declare the collections of the person\'s agent as a list';
        }
        $declared = [];
        foreach ($personal as $position => $collection) {
            if (!is_string($collection)) {
                $errors[] = static::class . " declares a collection of the person's agent at position {$position} that is "
                    . get_debug_type($collection);
                continue;
            }
            if (isset($declared[$collection])) {
                $errors[] = "Collection [{$collection}] of the person's agent is declared twice";
            }
            $declared[$collection] = true;
        }

        return $errors;
    }
}
